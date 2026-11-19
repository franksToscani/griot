<?php

use App\Models\Booking;
use App\Models\Provider;
use App\Models\Service;
use App\Notifications\BookingReceivedNotification;
use App\Support\PrivateExceptionLog;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;

// The log never gets the message of a database or mail transport error (issue #186,
// App\Support\PrivateExceptionLog, bootstrap/app.php): it can hold the customers' data and the
// secret code of a private link. Every other exception is reported as before. The log is written
// to a temporary file, read as text: what would reach the logs of the host.

const PEL_NAME = 'Cliente Prova';
const PEL_PHONE = '+39 000 000 0000';
const PEL_EMAIL = 'cliente@example.test';
const PEL_NOTES = 'Note di prova riservate';

beforeEach(function () {
    $this->pelLog = sys_get_temp_dir().'/griot-private-log-'.bin2hex(random_bytes(6)).'.log';
    config([
        'logging.channels.pel' => ['driver' => 'single', 'path' => $this->pelLog, 'level' => 'debug'],
        'logging.default' => 'pel',
    ]);
    Log::forgetChannel('pel');
});

afterEach(function () {
    if (is_file($this->pelLog)) {
        unlink($this->pelLog);
    }
});

/**
 * What the log holds so far.
 */
function pelLog(): string
{
    $path = test()->pelLog;

    return is_file($path) ? (string) file_get_contents($path) : '';
}

/**
 * A booking with the fake customer of these tests.
 */
function pelBooking(): Booking
{
    $provider = Provider::factory()->published()->create();
    $service = Service::factory()->for($provider)->create();

    return Booking::factory()->for($provider)->for($service)->create([
        'customer_name' => PEL_NAME,
        'customer_phone' => PEL_PHONE,
        'customer_email' => PEL_EMAIL,
        'notes' => PEL_NOTES,
        'locale' => 'it',
    ]);
}

test('a database error while a booking is saved: the log has the class and the SQLSTATE, never the data nor the code', function () {
    $booking = pelBooking();
    $token = $booking->public_token;
    expect(strlen($token))->toBe(48);

    // A second booking with the same secret code: the unique index refuses it.
    $row = DB::table('bookings')->where('id', $booking->id)->first();
    $copy = (array) $row;
    unset($copy['id']);

    try {
        DB::table('bookings')->insert($copy);
        $this->fail('The insert should have failed.');
    } catch (QueryException $exception) {
        // The danger is real: Laravel's message holds the values of the query.
        expect($exception->getMessage())->toContain(PEL_NAME)->toContain($token);

        report($exception);
    }

    $log = pelLog();

    expect($log)
        ->toContain('Database error (details hidden: they can hold personal data).')
        ->toContain(str_replace('\\', '\\\\', $exception::class))
        ->toMatch('/"sqlstate":"23\d{3}"/')
        ->toContain('"connection":"'.DB::connection()->getName().'"')
        ->toMatch('/"file":"[^"]+","line":\d+/')
        ->not->toContain(PEL_NAME)
        ->not->toContain(PEL_PHONE)
        ->not->toContain(PEL_EMAIL)
        ->not->toContain(PEL_NOTES)
        ->not->toContain($token)
        // Laravel's own report is stopped: one line, no stack trace with the message.
        ->not->toContain('SQLSTATE[')
        ->not->toContain('insert into');
    expect(substr_count($log, '.ERROR:'))->toBe(1);
});

test('a mail error in the queue worker: the log has the class and the SMTP code, never the address', function () {
    config(['queue.default' => 'database']);
    Mail::extend('pel-failing', fn () => new class extends AbstractTransport
    {
        protected function doSend(SentMessage $message): void
        {
            throw new TransportException('Expected response code "250" but got code "550", with message "550 5.1.1 <'.PEL_EMAIL.'>: Recipient address rejected".', 550);
        }

        public function __toString(): string
        {
            return 'pel-failing://';
        }
    });
    config(['mail.mailers.pel-failing' => ['transport' => 'pel-failing'], 'mail.default' => 'pel-failing']);

    BookingReceivedNotification::sendFor(pelBooking());
    expect(DB::table('jobs')->count())->toBe(1);

    // Three attempts, 60 and 300 seconds apart (BookingReceivedNotification::backoff()), as in
    // production: each one reported, then the job goes to failed_jobs.
    runQueueWorker()->assertSuccessful();
    $this->travel(61)->seconds();
    runQueueWorker()->assertSuccessful();
    $this->travel(301)->seconds();
    runQueueWorker()->assertSuccessful();

    $log = pelLog();
    expect(substr_count($log, 'Mail transport error (details hidden: they can hold an email address).'))->toBe(3);

    expect($log)
        ->toContain('Mail transport error (details hidden: they can hold an email address).')
        ->toContain(str_replace('\\', '\\\\', TransportException::class))
        ->toContain('"code":550')
        ->not->toContain(PEL_EMAIL)
        ->not->toContain('Recipient address rejected')
        ->not->toContain(PEL_NAME);
    // The job failed and is kept for 14 days, with its message, in the database only.
    expect(DB::table('failed_jobs')->count())->toBe(1);
});

test('every other exception is still reported by Laravel, with its message', function () {
    report(new RuntimeException('Un errore qualsiasi, senza dati personali'));

    expect(pelLog())
        ->toContain('Un errore qualsiasi, senza dati personali')
        ->toContain('RuntimeException')
        ->not->toContain('details hidden');
});

test('the two rules are registered in bootstrap/app.php, each one stopping Laravel\'s own report', function () {
    $bootstrap = (string) file_get_contents(base_path('bootstrap/app.php'));

    expect($bootstrap)
        ->toContain('$exceptions->report(fn (QueryException $exception) => PrivateExceptionLog::query($exception))->stop();')
        ->toContain('$exceptions->report(fn (TransportExceptionInterface $exception) => PrivateExceptionLog::mail($exception))->stop();');
    expect((string) file_get_contents(app_path('Support/PrivateExceptionLog.php')))
        ->not->toContain('getMessage()')
        ->not->toContain('getBindings()')
        ->not->toContain('getSql()')
        ->not->toContain('getTraceAsString()');
    expect(class_exists(PrivateExceptionLog::class))->toBeTrue();
});
