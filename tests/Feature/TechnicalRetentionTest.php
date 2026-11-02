<?php

use App\Support\ProductionMailer;
use Carbon\CarbonImmutable;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

// The retention of the technical data and the mailer in production (issue #56, phase 3). The
// commands run on the database of the tests only: SQLite in memory (phpunit.xml).

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-20 08:00', 'UTC'));
});

/**
 * The scheduled events of a command.
 *
 * @return list<Event>
 */
function trtEvents(string $command): array
{
    return collect(app(Schedule::class)->events())
        ->filter(fn (Event $event) => str_contains((string) $event->command, $command))
        ->values()
        ->all();
}

/**
 * A failed job, failed at the given UTC instant.
 */
function trtFailedJob(CarbonImmutable $failedAt): string
{
    $uuid = (string) Str::uuid();
    DB::table('failed_jobs')->insert([
        'uuid' => $uuid,
        'connection' => 'database',
        'queue' => 'default',
        'payload' => '{"displayName":"Test"}',
        'exception' => 'RuntimeException',
        'failed_at' => $failedAt,
    ]);

    return $uuid;
}

/**
 * Run the given callback as if the application were in this environment, then go back to testing.
 */
function trtInEnvironment(string $environment, Closure $callback): mixed
{
    app()->detectEnvironment(fn () => $environment);

    try {
        return $callback();
    } finally {
        app()->detectEnvironment(fn () => 'testing');
        // boot() in production forbids the destructive commands: the next tests need them.
        DB::prohibitDestructiveCommands(false);
    }
}

const TRT_SECRETS = ['MAIL_PASSWORD' => 'SEGRETO-DI-PROVA', 'MAIL_USERNAME' => 'utente-di-prova'];

/**
 * The environment of a separate process: SQLite in memory (never the real database), the log on
 * stderr (never laravel.log) and the cache in memory, plus the given variables.
 *
 * @param  array<string, string>  $variables
 * @return array<string, string>
 */
function trtEnvironment(array $variables): array
{
    return ['DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => ':memory:', 'LOG_CHANNEL' => 'stderr', 'CACHE_STORE' => 'array', ...$variables];
}

/**
 * "php artisan" with the given arguments in a separate process: the application boots all its
 * providers. A timeout, so that a worker can never stay running.
 *
 * @param  array<string, string>  $variables
 * @param  list<string>  $arguments
 */
function trtArtisan(array $variables, array $arguments): ProcessResult
{
    return Process::path(base_path())
        ->env(trtEnvironment($variables))
        ->timeout(30)
        ->run(['php', 'artisan', ...$arguments]);
}

/**
 * A web request to /up in a separate process, through the HTTP kernel as public/index.php does;
 * APP_RUNNING_IN_CONSOLE=false makes Laravel treat it as a web request. It prints the status code.
 *
 * @param  array<string, string>  $variables
 */
function trtWebRequest(array $variables): ProcessResult
{
    $script = <<<'PHP'
        require 'vendor/autoload.php';
        $app = require 'bootstrap/app.php';
        $response = $app->make(Illuminate\Contracts\Http\Kernel::class)->handle(Illuminate\Http\Request::create('/up'));
        echo $response->getStatusCode(), "\n", $response->getContent();
        PHP;

    return Process::path(base_path())
        ->env(trtEnvironment(['APP_RUNNING_IN_CONSOLE' => 'false', ...$variables]))
        ->timeout(30)
        ->run(['php', '-r', $script]);
}

/**
 * The refusal is there, without the mail username and password.
 */
function trtExpectRefusedWithoutSecrets(string $output): void
{
    expect($output)->toContain('The default mailer is "log" in production')
        ->not->toContain('SEGRETO-DI-PROVA')
        ->not->toContain('utente-di-prova');
}

test('the tests run on the database of the tests, never the real one', function () {
    expect(config('database.default'))->toBe('sqlite')
        ->and(config('database.connections.sqlite.database'))->toBe(':memory:')
        ->and(config('queue.failed.driver'))->toBe('database-uuids')
        ->and(config('queue.failed.table'))->toBe('failed_jobs');
});

// ---------------------------------------------------------------------------------------------
// Schedule
// ---------------------------------------------------------------------------------------------

test('the failed jobs older than 30 days are pruned every day, without overlapping', function () {
    $events = trtEvents('queue:prune-failed');

    expect($events)->toHaveCount(1);
    expect($events[0]->expression)->toBe('0 0 * * *')
        ->and($events[0]->withoutOverlapping)->toBeTrue()
        ->and((string) $events[0]->command)->toEndWith("'artisan' queue:prune-failed --hours=720");
});

test('the expired password reset tokens are cleared every day, without overlapping', function () {
    $events = trtEvents('auth:clear-resets');

    expect($events)->toHaveCount(1);
    expect($events[0]->expression)->toBe('0 0 * * *')
        ->and($events[0]->withoutOverlapping)->toBeTrue()
        ->and((string) $events[0]->command)->toEndWith("'artisan' auth:clear-resets");
});

// ---------------------------------------------------------------------------------------------
// The commands, really run
// ---------------------------------------------------------------------------------------------

test('a failed job older than 30 days is deleted, a more recent one stays', function () {
    $old = trtFailedJob(CarbonImmutable::now()->subHours(720)->subSecond());
    $recent = trtFailedJob(CarbonImmutable::now()->subHours(720)->addSecond());
    $today = trtFailedJob(CarbonImmutable::now()->subHour());

    $this->artisan('queue:prune-failed', ['--hours' => 720])->assertSuccessful();

    expect(DB::table('failed_jobs')->orderBy('id')->pluck('uuid')->all())->toBe([$recent, $today])
        ->and($old)->not->toBeIn(DB::table('failed_jobs')->pluck('uuid')->all());
});

test('an expired password reset token is deleted, a valid one stays', function () {
    // 60 minutes in config/auth.php.
    expect(config('auth.passwords.users.expire'))->toBe(60);
    DB::table('password_reset_tokens')->insert([
        ['email' => 'scaduto@example.com', 'token' => 'x', 'created_at' => CarbonImmutable::now()->subMinutes(61)],
        ['email' => 'valido@example.com', 'token' => 'y', 'created_at' => CarbonImmutable::now()->subMinutes(59)],
    ]);

    $this->artisan('auth:clear-resets')->assertSuccessful();

    expect(DB::table('password_reset_tokens')->pluck('email')->all())->toBe(['valido@example.com']);
});

// ---------------------------------------------------------------------------------------------
// The mailer in production
// ---------------------------------------------------------------------------------------------

test('in production a mailer that does not send is refused with a clear message', function (string $mailer) {
    config()->set('mail.default', $mailer);

    trtInEnvironment('production', fn () => ProductionMailer::ensureItSends(app()));
})->with(['log', 'array'])->throws(RuntimeException::class, 'in production: the emails would not be sent');

test('in production a real mailer is accepted', function (string $mailer) {
    config()->set('mail.default', $mailer);

    trtInEnvironment('production', fn () => ProductionMailer::ensureItSends(app()));

    expect(true)->toBeTrue();
})->with(['smtp', 'resend', 'postmark']);

test('in local and in the tests the log mailer gives no error', function (string $environment) {
    config()->set('mail.default', 'log');

    trtInEnvironment($environment, fn () => ProductionMailer::ensureItSends(app()));

    expect(true)->toBeTrue();
})->with(['local', 'testing']);

test('only the queue workers and the scheduler are checked before they start', function (string $command, bool $checked) {
    config()->set('mail.default', 'log');
    $start = fn () => event(new CommandStarting($command, new ArrayInput([]), new NullOutput));

    if ($checked) {
        expect(fn () => trtInEnvironment('production', $start))->toThrow(RuntimeException::class, 'Set MAIL_MAILER to a real mailer.');
    } else {
        trtInEnvironment('production', $start);
    }

    // In the tests nothing is ever stopped.
    $start();
    expect(ProductionMailer::SENDING_COMMANDS)->toBe(['queue:work', 'queue:listen', 'schedule:run', 'schedule:work']);
})->with([
    'queue:work' => ['queue:work', true],
    'queue:listen' => ['queue:listen', true],
    'schedule:run' => ['schedule:run', true],
    'schedule:work' => ['schedule:work', true],
    'package:discover' => ['package:discover', false],
    'migrate' => ['migrate', false],
    'config:cache' => ['config:cache', false],
    'wayfinder:generate' => ['wayfinder:generate', false],
    'tinker' => ['tinker', false],
    'bookings:anonymize-old' => ['bookings:anonymize-old', false],
]);

test('boot checks the web requests only, first, and listens to the commands', function () {
    expect((string) file_get_contents(app_path('Providers/AppServiceProvider.php')))
        ->toMatch('/public function boot\(\): void\s*\{\s*\$this->configureProductionMailer\(\);\s*\$this->configureDefaults\(\);/')
        ->toMatch('/if \(! \$this->app->runningInConsole\(\)\) \{\s*ProductionMailer::ensureItSends\(\$this->app\);\s*\}/')
        ->toMatch('/Event::listen\(CommandStarting::class, function \(CommandStarting \$event\): void \{\s*ProductionMailer::ensureItSendsBefore\(\$event->command, \$this->app\);\s*\}\);/');
});

test('without .env (a fresh clone, the CI) package:discover and the other commands start', function (string $command) {
    // Laravel's defaults without .env: APP_ENV=production and MAIL_MAILER=log. "composer install"
    // runs package:discover.
    $result = trtArtisan(['APP_ENV' => 'production', 'MAIL_MAILER' => 'log'], [$command]);

    expect($result->successful())->toBeTrue($result->output().$result->errorOutput());
})->with(['package:discover', '--version']);

test('in production with MAIL_MAILER=log the queue worker and the scheduler do not start, and show no secret', function (array $arguments) {
    $result = trtArtisan(['APP_ENV' => 'production', 'MAIL_MAILER' => 'log', ...TRT_SECRETS], $arguments);

    expect($result->successful())->toBeFalse();
    trtExpectRefusedWithoutSecrets($result->output().$result->errorOutput());
})->with([
    'queue:work' => [['queue:work', 'sync', '--once', '--stop-when-empty']],
    'schedule:run' => [['schedule:run']],
]);

test('in production with MAIL_MAILER=log a web request fails, and shows no secret', function () {
    $result = trtWebRequest(['APP_ENV' => 'production', 'APP_DEBUG' => 'false', 'MAIL_MAILER' => 'log', ...TRT_SECRETS]);

    // The page is Laravel's error page: the reason is in the log (stderr here), never on the page.
    expect(trim($result->output()))->toStartWith("500\n")
        ->not->toContain('MAIL_MAILER');
    trtExpectRefusedWithoutSecrets($result->errorOutput());
    expect($result->output())->not->toContain('SEGRETO-DI-PROVA');
});

test('in production with smtp the web, the queue worker and the scheduler start', function () {
    $production = ['APP_ENV' => 'production', 'APP_DEBUG' => 'false', 'MAIL_MAILER' => 'smtp'];

    expect(trim(trtWebRequest($production)->output()))->toStartWith("200\n");

    $worker = trtArtisan($production, ['queue:work', 'sync', '--once', '--stop-when-empty']);
    expect($worker->successful())->toBeTrue($worker->output().$worker->errorOutput());

    // The scheduler may find a command due at this minute, run on SQLite in memory: only the mailer
    // check matters here.
    expect(trtArtisan($production, ['schedule:run'])->errorOutput())->not->toContain('The default mailer is');
});

test('in local with log everything starts', function () {
    $local = ['APP_ENV' => 'local', 'MAIL_MAILER' => 'log'];

    expect(trim(trtWebRequest($local)->output()))->toStartWith("200\n")
        ->and(trtArtisan($local, ['queue:work', 'sync', '--once', '--stop-when-empty'])->successful())->toBeTrue();
});

// ---------------------------------------------------------------------------------------------
// The example environment
// ---------------------------------------------------------------------------------------------

test('the example environment explains the log and the mailer for production, and keeps the local defaults', function () {
    $example = (string) file_get_contents(base_path('.env.example'));

    expect($example)
        ->toContain("# In production: LOG_CHANNEL=stderr (the host keeps the log), or LOG_CHANNEL=daily with\n# LOG_DAILY_DAYS=14 so that old files are deleted.")
        ->toContain("LOG_CHANNEL=stack\nLOG_STACK=single\n")
        ->toContain("# \"log\" writes every email, whole, in laravel.log: local development only. Never in production,\n# where with \"log\" or \"array\" the web pages, the queue worker and the scheduler refuse to start\n# (App\\Support\\ProductionMailer); migrate and the build still work.\nMAIL_MAILER=log\n");
});
