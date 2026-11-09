<?php

use App\Support\FailedJobsReport;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

// The hourly warning about the failed jobs (issue #63, docs/deploy.md): the number of new rows of
// failed_jobs in the log, never a personal datum (issue #186).

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-20 08:00', 'UTC'));
    Cache::forget(FailedJobsReport::CACHE_KEY);
});

/**
 * A failed job as Laravel writes it, with the customer's email in the payload and in the exception,
 * as for the answer to a booking. Returns its id.
 */
function fjrFailedJob(string $class = 'App\\Notifications\\BookingStatusNotification', ?string $payload = null, string $failedAt = '2026-11-20 07:30:00'): int
{
    return (int) DB::table('failed_jobs')->insertGetId([
        'uuid' => (string) Str::uuid(),
        'connection' => 'database',
        'queue' => 'default',
        'payload' => $payload ?? json_encode([
            'displayName' => $class,
            'data' => ['command' => 'O:48:"Illuminate\\Notifications\\SendQueuedNotifications":{"notifiables":"cliente@example.test"}'],
        ], JSON_THROW_ON_ERROR),
        'exception' => 'Symfony\\Component\\Mailer\\Exception\\TransportException: cannot send to cliente@example.test',
        'failed_at' => $failedAt,
    ]);
}

/**
 * Run the report with the log spied, and give back what it wrote with Log::error.
 *
 * @return array{count: int, errors: list<array{message: string, context: array<string, mixed>}>}
 */
function fjrRun(): array
{
    // A new collector at each run: the report writes only with Log::error.
    $log = new class
    {
        /** @var list<array{message: string, context: array<string, mixed>}> */
        public array $errors = [];

        /**
         * @param  array<string, mixed>  $context
         */
        public function error(string $message, array $context = []): void
        {
            $this->errors[] = ['message' => $message, 'context' => $context];
        }
    };
    Log::swap($log);

    $count = app(FailedJobsReport::class)->run();

    return ['count' => $count, 'errors' => $log->errors];
}

test('the command runs every hour, without overlapping', function () {
    $events = collect(app(Schedule::class)->events())
        ->filter(fn (Event $event) => str_contains((string) $event->command, 'jobs:report-failed'))
        ->values();

    expect($events)->toHaveCount(1);
    expect($events[0]->expression)->toBe('0 * * * *')
        ->and($events[0]->withoutOverlapping)->toBeTrue()
        ->and((string) $events[0]->command)->toEndWith("'artisan' jobs:report-failed");
});

test('without failed jobs nothing is written', function () {
    $result = fjrRun();

    expect($result['count'])->toBe(0)
        ->and($result['errors'])->toBe([]);
});

test('the first run reports every failed job: the count, the classes and the times, nothing personal', function () {
    fjrFailedJob(failedAt: '2026-11-20 06:10:00');
    fjrFailedJob(failedAt: '2026-11-20 07:45:00');
    fjrFailedJob('App\\Notifications\\NewBookingNotification', failedAt: '2026-11-20 07:00:00');

    $result = fjrRun();

    expect($result['count'])->toBe(3)
        ->and($result['errors'])->toHaveCount(1)
        ->and($result['errors'][0]['message'])->toBe(FailedJobsReport::MESSAGE)
        ->and($result['errors'][0]['context'])->toBe([
            'count' => 3,
            'jobs' => [
                'App\\Notifications\\BookingStatusNotification' => 2,
                'App\\Notifications\\NewBookingNotification' => 1,
            ],
            'first_failed_at' => '2026-11-20 06:10:00',
            'last_failed_at' => '2026-11-20 07:45:00',
        ]);

    // No email, no payload, no exception, no uuid in what reaches the log.
    $written = json_encode($result['errors'], JSON_THROW_ON_ERROR);
    expect($written)->not->toContain('example.test')
        ->not->toContain('notifiables')
        ->not->toContain('TransportException');
    foreach (DB::table('failed_jobs')->pluck('uuid') as $uuid) {
        expect($written)->not->toContain((string) $uuid);
    }
});

test('a second run reports only the jobs failed since the first', function () {
    fjrFailedJob();
    fjrFailedJob();
    expect(fjrRun()['count'])->toBe(2);

    // Nothing new: no line.
    expect(fjrRun())->toBe(['count' => 0, 'errors' => []]);

    fjrFailedJob('App\\Notifications\\CustomerCancelledNotification');
    $result = fjrRun();

    expect($result['count'])->toBe(1)
        ->and($result['errors'][0]['context']['jobs'])->toBe(['App\\Notifications\\CustomerCancelledNotification' => 1]);
});

test('after queue:prune-failed the old rows are gone and only a new failure is reported', function () {
    fjrFailedJob(failedAt: '2026-10-01 08:00:00');
    expect(fjrRun()['count'])->toBe(1);

    $this->artisan('queue:prune-failed', ['--hours' => 720])->assertSuccessful();
    expect(DB::table('failed_jobs')->count())->toBe(0);

    // The table is empty: nothing to say.
    expect(fjrRun())->toBe(['count' => 0, 'errors' => []]);

    // The ids go on after the prune: the new row is above the last one seen.
    fjrFailedJob();
    expect(fjrRun()['count'])->toBe(1);
});

test('a table that started again, or a cache that was emptied, reports every row again', function () {
    fjrFailedJob();
    fjrFailedJob();

    // The last id seen is above every id of the table: its ids started again.
    Cache::forever(FailedJobsReport::CACHE_KEY, 1000);
    expect(fjrRun()['count'])->toBe(2);

    // The cache emptied: like a first run.
    Cache::forget(FailedJobsReport::CACHE_KEY);
    expect(fjrRun()['count'])->toBe(2);

    // And then nothing new.
    expect(fjrRun()['count'])->toBe(0);
});

test('a payload that cannot be read, or a strange class name, is counted as "unknown"', function () {
    fjrFailedJob(payload: 'not json');
    fjrFailedJob(payload: json_encode(['displayName' => 'cliente@example.test'], JSON_THROW_ON_ERROR));
    fjrFailedJob(payload: json_encode(['data' => []], JSON_THROW_ON_ERROR));

    $result = fjrRun();

    expect($result['errors'][0]['context']['jobs'])->toBe(['unknown' => 3])
        ->and(json_encode($result['errors'], JSON_THROW_ON_ERROR))->not->toContain('example.test');
});

test('the last id seen is the highest one, even beyond a block of rows', function () {
    for ($row = 0; $row < 205; $row++) {
        fjrFailedJob();
    }

    expect(fjrRun()['count'])->toBe(205)
        ->and(Cache::get(FailedJobsReport::CACHE_KEY))->toBe((int) DB::table('failed_jobs')->max('id'));
});

test('the command succeeds and says how many, in the language of the site', function () {
    fjrFailedJob();
    Log::spy();

    $this->artisan('jobs:report-failed')
        ->expectsOutput("1 job falliti nuovi dall'ultimo controllo.")
        ->assertSuccessful();
    $this->artisan('jobs:report-failed')
        ->expectsOutput("0 job falliti nuovi dall'ultimo controllo.")
        ->assertSuccessful();

    Log::shouldHaveReceived('error')->once();
    expect(__('console.failed_jobs.summary', ['count' => 2], 'fr'))->toBe('2 tâches échouées nouvelles depuis le dernier contrôle.')
        ->and(__('console.failed_jobs.summary', ['count' => 2], 'en'))->toBe('2 new failed jobs since the last check.');
});

test('the code never writes the payload, the exception or the uuid in the log', function () {
    $source = (string) file_get_contents(app_path('Support/FailedJobsReport.php'));

    expect($source)
        ->toContain("->select(['id', 'payload', 'failed_at'])")
        ->not->toMatch('/Log::\\w+\\([^;]*(payload|exception|uuid)/s')
        ->not->toContain('DB::raw')
        ->not->toContain('whereRaw');
});
