<?php

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Provider;
use App\Models\Service;
use App\Models\ServiceOption;
use App\Notifications\PendingReminderNotification;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Notifications\Dispatcher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Mail\Mailer;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Mime\Email;

// "Now" is Tuesday 20 October 2026, 08:00 UTC (10:00 in Italy, summer time). The reference booking
// is on Thursday 29 October, 08:00-10:45 UTC: winter time, 09:00-11:45 in Italy. With the default
// threshold of 720 minutes, a request qualifies when it was created at or before 20:00 UTC of 19 October.
beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-20 08:00', 'UTC'));
    config()->set('booking.reminder_after_minutes', 720);
    app()->setLocale('it');
});

const PRM_SUMMARY = ':queued promemoria accodati, :skipped saltati.';

function prmProvider(string $name = 'Salone Awa'): Provider
{
    return Provider::factory()->create(['name' => $name]);
}

/**
 * The reference booking: "Treccine" by the provider, pending, created exactly 720 minutes ago.
 *
 * @param  array<string, mixed>  $override
 */
function prmBooking(?Provider $provider = null, array $override = []): Booking
{
    $provider ??= prmProvider();
    $service = Service::factory()->for($provider)->create(['name' => 'Treccine', 'duration_min' => 120, 'duration_max' => 165]);

    return Booking::factory()->for($provider)->for($service)->create([
        'status' => BookingStatus::Pending,
        'customer_name' => 'Awa Diop',
        'customer_phone' => '+393331234567',
        'customer_email' => 'awa@example.com',
        'notes' => 'NOTA PRIVATA DEL CLIENTE',
        'starts_at' => CarbonImmutable::parse('2026-10-29 08:00', 'UTC'),
        'ends_at' => CarbonImmutable::parse('2026-10-29 10:45', 'UTC'),
        'created_at' => CarbonImmutable::now()->subMinutes(720),
        'updated_at' => CarbonImmutable::now()->subMinutes(720),
        ...$override,
    ]);
}

/**
 * Run the command and check its summary line.
 */
function prmRun(int $queued, int $skipped): void
{
    test()->artisan('bookings:remind-pending')
        ->expectsOutput("{$queued} promemoria accodati, {$skipped} saltati.")
        ->assertSuccessful();
}

function prmTransport(): ArrayTransport
{
    $mailer = Mail::mailer();
    assert($mailer instanceof Mailer);
    $transport = $mailer->getSymfonyTransport();
    assert($transport instanceof ArrayTransport);

    return $transport;
}

/**
 * The emails really sent, through the array transport.
 *
 * @return list<Email>
 */
function prmSent(): array
{
    $emails = [];

    foreach (prmTransport()->messages() as $sent) {
        $email = $sent->getOriginalMessage();
        assert($email instanceof Email);
        $emails[] = $email;
    }

    return $emails;
}

/**
 * Subject, HTML and text of the reminder of a booking.
 *
 * @return array{subject: string, html: string, text: string}
 */
function prmMail(Booking $booking): array
{
    $mail = (new PendingReminderNotification($booking))->toMail($booking->provider->user);

    return [
        'subject' => (string) $mail->subject,
        'html' => (string) $mail->render(),
        'text' => view($mail->view['text'], $mail->viewData)->render(),
    ];
}

/**
 * Change a booking right after the command has read the ids, before it locks the row: what another
 * run, the provider or the customer could do in between.
 *
 * @param  array<string, mixed>  $columns
 */
function prmChangeAfterTheIds(Booking $booking, array $columns): void
{
    $done = false;
    DB::listen(function (QueryExecuted $query) use ($booking, $columns, &$done) {
        if (! $done && str_starts_with($query->sql, 'select "id" from "bookings"')) {
            $done = true;
            DB::table('bookings')->where('id', $booking->id)->update($columns);
        }
    });
}

// ---------------------------------------------------------------------------------------------
// Which requests are reminded
// ---------------------------------------------------------------------------------------------

test('a request pending for 720 minutes is reminded to its provider\'s user, once, and marked', function () {
    Notification::fake();
    $booking = prmBooking();
    prmBooking(prmProvider('Barberia Kofi'), ['created_at' => CarbonImmutable::now()->subMinutes(10)]);

    prmRun(1, 0);

    Notification::assertSentTo($booking->provider->user, PendingReminderNotification::class, fn (PendingReminderNotification $notification) => $notification->booking->is($booking));
    Notification::assertCount(1);
    expect($booking->fresh()->reminder_sent_at?->toIso8601ZuluString())->toBe('2026-10-20T08:00:00Z');
});

test('the threshold is exact to the minute', function (int $minutes, bool $reminded) {
    Notification::fake();
    $booking = prmBooking(override: ['created_at' => CarbonImmutable::now()->subMinutes($minutes)]);

    prmRun($reminded ? 1 : 0, 0);

    expect($booking->fresh()->reminder_sent_at !== null)->toBe($reminded);
    Notification::assertCount($reminded ? 1 : 0);
})->with([
    '719 minutes' => [719, false],
    '720 minutes' => [720, true],
    '721 minutes' => [721, true],
]);

test('the threshold comes from the config', function () {
    Notification::fake();
    config()->set('booking.reminder_after_minutes', 60);
    $booking = prmBooking(override: ['created_at' => CarbonImmutable::now()->subMinutes(60)]);

    prmRun(1, 0);

    expect(prmMail($booking->fresh())['text'])->toContain('Questa richiesta aspetta la tua risposta da più di 1 ore.');
});

test('confirmed, cancelled, provider-made, already reminded and started bookings get nothing', function (array $override) {
    Notification::fake();
    $booking = prmBooking(override: $override);
    $before = $booking->fresh()->reminder_sent_at;

    prmRun(0, 0);

    Notification::assertNothingSent();
    expect($booking->fresh()->reminder_sent_at?->toIso8601ZuluString())->toBe($before?->toIso8601ZuluString());
})->with([
    'confirmed' => [['status' => BookingStatus::Confirmed]],
    'cancelled' => [['status' => BookingStatus::Cancelled]],
    // A booking the provider writes is born confirmed: it can never be pending.
    'made by the provider' => [['status' => BookingStatus::Confirmed, 'created_by_provider' => true]],
    'already reminded' => [['reminder_sent_at' => CarbonImmutable::parse('2026-10-20 07:00', 'UTC')]],
    'starting now' => [['starts_at' => CarbonImmutable::parse('2026-10-20 08:00', 'UTC'), 'ends_at' => CarbonImmutable::parse('2026-10-20 09:00', 'UTC')]],
    'already over' => [['starts_at' => CarbonImmutable::parse('2026-10-19 08:00', 'UTC'), 'ends_at' => CarbonImmutable::parse('2026-10-19 09:00', 'UTC')]],
]);

test('two runs in a row send one reminder only', function () {
    Notification::fake();
    $booking = prmBooking();

    prmRun(1, 0);
    $this->travel(15)->minutes();
    prmRun(0, 0);

    Notification::assertSentToTimes($booking->provider->user, PendingReminderNotification::class, 1);
});

test('the reminder writes reminder_sent_at only: updated_at and the other columns stay', function () {
    Notification::fake();
    $booking = prmBooking();
    $before = (array) DB::table('bookings')->where('id', $booking->id)->first();

    prmRun(1, 0);

    $after = (array) DB::table('bookings')->where('id', $booking->id)->first();
    expect(array_diff_key($after, ['reminder_sent_at' => true]))->toBe(array_diff_key($before, ['reminder_sent_at' => true]));
    expect($after['updated_at'])->toBe($before['updated_at']);
    expect($before['reminder_sent_at'])->toBeNull()->and($after['reminder_sent_at'])->not->toBeNull();
});

test('the migration adds the column and its index, and takes both away', function () {
    $migration = require database_path('migrations/2026_11_01_090000_add_reminder_sent_at_to_bookings_table.php');
    $booking = prmBooking();

    expect(Schema::hasColumn('bookings', 'reminder_sent_at'))->toBeTrue()
        ->and(Schema::hasIndex('bookings', 'bookings_reminder_index'))->toBeTrue();

    $migration->down();
    expect(Schema::hasColumn('bookings', 'reminder_sent_at'))->toBeFalse()
        ->and(Schema::hasIndex('bookings', 'bookings_reminder_index'))->toBeFalse();

    // The bookings made before the column were never reminded.
    $migration->up();
    expect(DB::table('bookings')->where('id', $booking->id)->value('reminder_sent_at'))->toBeNull()
        ->and(Schema::hasIndex('bookings', 'bookings_reminder_index'))->toBeTrue();
});

test('the older public_token migration, rolled back and run again, keeps the column and the index', function () {
    // What PublicTokenMigrationTest does: on SQLite the table is rebuilt, with every other column and index.
    $migration = require database_path('migrations/2026_10_18_101140_add_public_token_to_bookings_table.php');
    prmBooking(override: ['reminder_sent_at' => CarbonImmutable::parse('2026-10-20 07:00', 'UTC')]);

    $migration->down();
    $migration->up();

    expect(Schema::hasIndex('bookings', 'bookings_reminder_index'))->toBeTrue()
        ->and(DB::table('bookings')->value('reminder_sent_at'))->toBe('2026-10-20 07:00:00');
});

test('reminder_sent_at is not fillable', function () {
    $booking = new Booking(['reminder_sent_at' => CarbonImmutable::now()]);

    expect($booking->isFillable('reminder_sent_at'))->toBeFalse()
        ->and($booking->reminder_sent_at)->toBeNull();
});

// ---------------------------------------------------------------------------------------------
// Lock and second reading
// ---------------------------------------------------------------------------------------------

test('a booking changed after the ids were read is read again under the lock and skipped', function (array $columns) {
    Notification::fake();
    $booking = prmBooking();
    prmChangeAfterTheIds($booking, $columns);

    prmRun(0, 1);

    Notification::assertNothingSent();
})->with([
    'confirmed by the provider' => [['status' => BookingStatus::Confirmed->value]],
    'cancelled by the customer' => [['status' => BookingStatus::Cancelled->value]],
    'reminded by another run' => [['reminder_sent_at' => '2026-10-20 07:59:00']],
]);

test('a failure while queueing is reported, the booking stays not reminded and the others go on', function () {
    Exceptions::fake();
    $this->mock(Dispatcher::class)
        ->shouldReceive('send')
        ->twice()
        ->andThrow(new RuntimeException('Coda non disponibile'));
    $first = prmBooking();
    $second = prmBooking(prmProvider('Barberia Kofi'));

    prmRun(0, 2);

    expect($first->fresh()->reminder_sent_at)->toBeNull()
        ->and($second->fresh()->reminder_sent_at)->toBeNull();
    Exceptions::assertReported(fn (RuntimeException $exception) => $exception->getMessage() === 'Coda non disponibile');
});

test('with a real queue the job is stored with the mark, and nothing more until the worker runs', function () {
    config(['queue.default' => 'database']);
    $booking = prmBooking();

    prmRun(1, 0);

    expect(DB::table('jobs')->count())->toBe(1)
        ->and($booking->fresh()->reminder_sent_at)->not->toBeNull()
        ->and(prmTransport()->messages())->toBeEmpty();

    $this->artisan('queue:work', ['--once' => true, '--stop-when-empty' => true])->assertSuccessful();

    expect(DB::table('jobs')->count())->toBe(0)
        ->and(DB::table('failed_jobs')->count())->toBe(0)
        ->and(prmSent())->toHaveCount(1);
    expect(prmSent()[0]->getTo()[0]->getAddress())->toBe($booking->provider->user->email);
});

test('the email is queued, not sent while the command runs', function () {
    Queue::fake();
    prmBooking();

    prmRun(1, 0);

    Queue::assertCount(1);
    Queue::assertPushed(SendQueuedNotifications::class, fn (SendQueuedNotifications $job) => $job->notification instanceof PendingReminderNotification);
});

// ---------------------------------------------------------------------------------------------
// shouldSend
// ---------------------------------------------------------------------------------------------

test('shouldSend reads the status again: false once the request is answered or cancelled', function (BookingStatus $status, bool $send) {
    $booking = prmBooking();
    $notification = new PendingReminderNotification($booking);

    DB::table('bookings')->where('id', $booking->id)->update(['status' => $status->value]);

    expect($notification->shouldSend($booking->provider->user, 'mail'))->toBe($send);
})->with([
    'still pending' => [BookingStatus::Pending, true],
    'confirmed' => [BookingStatus::Confirmed, false],
    'cancelled' => [BookingStatus::Cancelled, false],
]);

test('a request confirmed while the job waits in the queue gets no email', function () {
    config(['queue.default' => 'database']);
    $booking = prmBooking();
    prmRun(1, 0);

    DB::table('bookings')->where('id', $booking->id)->update(['status' => BookingStatus::Confirmed->value]);
    $this->artisan('queue:work', ['--once' => true, '--stop-when-empty' => true])->assertSuccessful();

    expect(DB::table('jobs')->count())->toBe(0)
        ->and(DB::table('failed_jobs')->count())->toBe(0)
        ->and(prmTransport()->messages())->toBeEmpty();
});

test('a job for a booking deleted in the meantime is discarded without failing', function () {
    config(['queue.default' => 'database']);
    $booking = prmBooking();
    prmRun(1, 0);

    $booking->delete();
    $this->artisan('queue:work', ['--once' => true, '--stop-when-empty' => true])->assertSuccessful();

    expect(DB::table('jobs')->count())->toBe(0)
        ->and(DB::table('failed_jobs')->count())->toBe(0)
        ->and(prmTransport()->messages())->toBeEmpty();
});

// ---------------------------------------------------------------------------------------------
// The email
// ---------------------------------------------------------------------------------------------

test('subject, greeting, the hours of the threshold and the link to the pending requests', function () {
    $booking = prmBooking();
    $mail = prmMail($booking);
    $listUrl = route('provider.bookings.index', ['status' => 'pending']);

    expect($mail['subject'])->toBe('Richiesta in attesa: Treccine');
    expect($mail['text'])->toBe(implode("\n", [
        'Ciao Salone Awa,',
        '',
        'Questa richiesta aspetta la tua risposta da più di 12 ore.',
        '',
        'Servizio: Treccine',
        'Quando: giovedì 29 ottobre 2026, 09:00–11:45',
        'Cliente: Awa Diop',
        'Telefono: +393331234567',
        '',
        'Vai alle richieste in attesa: '.$listUrl,
        '',
    ]));
    expect($mail['html'])
        ->toContain('Ciao Salone Awa,')
        ->toContain('Questa richiesta aspetta la tua risposta da più di 12 ore.')
        ->toContain('<a href="'.e($listUrl).'"')
        ->toContain('Vai alle richieste in attesa</a>');
    expect($listUrl)->toBe(url('/provider/bookings?status=pending'));
});

test('the local time, in winter and in summer', function (string $start, string $end, string $when) {
    $booking = prmBooking(override: [
        'starts_at' => CarbonImmutable::parse($start, 'UTC'),
        'ends_at' => CarbonImmutable::parse($end, 'UTC'),
    ]);
    $mail = prmMail($booking);

    expect($mail['text'])->toContain("Quando: {$when}");
    expect($mail['html'])->toContain(str_replace(', ', '<br>', $when));
})->with([
    // Summer time, UTC+2.
    'summer' => ['2026-10-22 07:00', '2026-10-22 09:45', 'giovedì 22 ottobre 2026, 09:00–11:45'],
    // Winter time, UTC+1.
    'winter' => ['2026-10-29 08:00', '2026-10-29 10:45', 'giovedì 29 ottobre 2026, 09:00–11:45'],
]);

test('the options appear only when there are some, ordered by id', function () {
    $booking = prmBooking();
    $second = ServiceOption::factory()->for($booking->service)->create(['name' => 'Perline']);
    $first = ServiceOption::factory()->for($booking->service)->create(['name' => 'Capelli lunghi']);
    expect(prmMail($booking)['text'])->not->toContain('Opzioni');

    $booking->options()->attach([$first->id, $second->id]);
    $mail = prmMail($booking->fresh());

    expect($mail['text'])->toContain('Opzioni: Perline, Capelli lunghi');
    expect($mail['html'])->toContain('Perline, Capelli lunghi');
});

test('no notes, no customer email, no token and no private address, in subject, HTML, text and sent message', function () {
    $booking = prmBooking();
    prmRun(1, 0);

    $mail = prmMail($booking->fresh());
    $sent = prmSent();
    expect($sent)->toHaveCount(1);
    $raw = $sent[0]->toString();
    $private = route('bookings.show', ['booking' => $booking->public_token]);

    foreach ([$mail['subject'], $mail['html'], $mail['text'], $raw] as $part) {
        expect($part)
            ->not->toContain('NOTA PRIVATA DEL CLIENTE')
            ->not->toContain('awa@example.com')
            ->not->toContain($booking->public_token)
            ->not->toContain($private)
            ->not->toContain('/bookings/');
    }
});

test('the phone is plain text, not a link, and what the customer wrote is never Markdown or HTML', function () {
    $booking = prmBooking(override: ['customer_name' => '<b>Awa</b> [link](https://evil.example) **Diop**']);
    $mail = prmMail($booking);

    expect($mail['html'])
        ->not->toContain('tel:')
        ->not->toContain('<b>Awa</b>')
        ->not->toContain('https://evil.example"')
        ->toContain('&lt;b&gt;Awa&lt;/b&gt; [link](https://evil.example) **Diop**');
    expect($mail['text'])->toContain('Cliente: <b>Awa</b> [link](https://evil.example) **Diop**');
    // The only link is the provider's list.
    expect(substr_count($mail['html'], '<a '))->toBe(1);
    expect($mail['subject'])->toBe('Richiesta in attesa: Treccine');
});

test('mail channel only, queued, three attempts and the backoff', function () {
    $booking = prmBooking();
    $notification = new PendingReminderNotification($booking);

    expect($notification)->toBeInstanceOf(ShouldQueue::class)
        ->and($notification->via($booking->provider->user))->toBe(['mail'])
        ->and($notification->tries)->toBe(3)
        ->and($notification->backoff())->toBe([60, 300])
        ->and($notification->deleteWhenMissingModels)->toBeTrue();
});

// ---------------------------------------------------------------------------------------------
// Queries and schedule
// ---------------------------------------------------------------------------------------------

test('the queries per reminder are the same for every candidate, whatever the options', function () {
    Notification::fake();

    // Each reminder needs its own lock, mark and job: the cost per candidate is fixed, never more.
    $count = function (int $candidates, int $options) {
        DB::table('bookings')->delete();

        for ($i = 1; $i <= $candidates; $i++) {
            $booking = prmBooking(prmProvider("Salone {$candidates} {$options} {$i}"));
            $booking->options()->attach(ServiceOption::factory()->count($options)->for($booking->service)->create()->modelKeys());
        }

        $queries = 0;
        DB::listen(function () use (&$queries) {
            $queries++;
        });
        test()->artisan('bookings:remind-pending')->assertSuccessful();

        return $queries;
    };

    $none = $count(0, 0);
    $one = $count(1, 0) - $none;

    expect($one)->toBeGreaterThan(0);
    expect($count(1, 5) - $none)->toBe($one);
    expect($count(3, 0) - $none)->toBe(3 * $one);
    expect($count(3, 5) - $none)->toBe(3 * $one);
});

test('the command is scheduled every 15 minutes, without overlapping', function () {
    $events = collect(app(Schedule::class)->events())
        ->filter(fn (Event $event) => str_contains((string) $event->command, 'bookings:remind-pending'))
        ->values();

    expect($events)->toHaveCount(1);
    expect($events[0]->expression)->toBe('*/15 * * * *')
        ->and($events[0]->withoutOverlapping)->toBeTrue();
});

// ---------------------------------------------------------------------------------------------
// Texts
// ---------------------------------------------------------------------------------------------

test('the Italian texts are exactly the expected ones', function () {
    $texts = [
        'booking.reminder.subject' => 'Richiesta in attesa: :service',
        'booking.reminder.greeting' => 'Ciao :name,',
        'booking.reminder.intro' => 'Questa richiesta aspetta la tua risposta da più di :hours ore.',
        'booking.reminder.service' => 'Servizio',
        'booking.reminder.options' => 'Opzioni',
        'booking.reminder.when' => 'Quando',
        'booking.reminder.customer' => 'Cliente',
        'booking.reminder.phone' => 'Telefono',
        'booking.reminder.action' => 'Vai alle richieste in attesa',
        'booking.reminder.command_summary' => PRM_SUMMARY,
    ];

    foreach ($texts as $key => $text) {
        expect(__($key))->toBe($text);
    }
});
