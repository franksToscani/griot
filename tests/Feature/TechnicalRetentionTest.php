<?php

use App\Providers\AppServiceProvider;
use App\Support\ProductionMailer;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

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

/**
 * "php artisan --version" in a separate process, with the given environment variables: the
 * application boots all its providers, as for any request or command. The database is SQLite in
 * memory, never the real one.
 *
 * @param  array<string, string>  $variables
 */
function trtBoot(array $variables): ProcessResult
{
    return Process::path(base_path())
        ->env(['DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => ':memory:', ...$variables])
        ->run(['php', 'artisan', '--version']);
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

test('the check runs first in boot: in production with "log" nothing else is configured', function () {
    config()->set('mail.default', 'log');

    expect(fn () => trtInEnvironment('production', fn () => (new AppServiceProvider(app()))->boot()))
        ->toThrow(RuntimeException::class, 'Set MAIL_MAILER to a real mailer.');
    expect((string) file_get_contents(app_path('Providers/AppServiceProvider.php')))
        ->toMatch('/public function boot\(\): void\s*\{\s*\/\/[^\n]*\n\s*ProductionMailer::ensureItSends\(\$this->app\);\s*\n\s*\$this->configureDefaults\(\);/');
});

test('the application does not start in production with MAIL_MAILER=log, and the message has no secret', function () {
    $result = trtBoot(['APP_ENV' => 'production', 'MAIL_MAILER' => 'log', 'MAIL_PASSWORD' => 'SEGRETO-DI-PROVA', 'MAIL_USERNAME' => 'utente-di-prova']);

    expect($result->successful())->toBeFalse();
    $output = $result->output().$result->errorOutput();
    expect($output)->toContain('The default mailer is "log" in production')
        ->not->toContain('SEGRETO-DI-PROVA')
        ->not->toContain('utente-di-prova');
});

test('the application starts in production with a real mailer, and in local with log', function (array $variables) {
    $result = trtBoot($variables);

    expect($result->successful())->toBeTrue($result->errorOutput())
        ->and($result->output())->toContain('Laravel Framework');
})->with([
    'production with smtp' => [['APP_ENV' => 'production', 'MAIL_MAILER' => 'smtp']],
    'local with log' => [['APP_ENV' => 'local', 'MAIL_MAILER' => 'log']],
]);

// ---------------------------------------------------------------------------------------------
// The example environment
// ---------------------------------------------------------------------------------------------

test('the example environment explains the log and the mailer for production, and keeps the local defaults', function () {
    $example = (string) file_get_contents(base_path('.env.example'));

    expect($example)
        ->toContain("# In production: LOG_CHANNEL=stderr (the host keeps the log), or LOG_CHANNEL=daily with\n# LOG_DAILY_DAYS=14 so that old files are deleted.")
        ->toContain("LOG_CHANNEL=stack\nLOG_STACK=single\n")
        ->toContain("# \"log\" writes every email, whole, in laravel.log: local development only. Never in production,\n# where the application refuses to start with \"log\" or \"array\" (App\\Support\\ProductionMailer).\nMAIL_MAILER=log\n");
});
