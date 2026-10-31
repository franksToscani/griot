<?php

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Provider;
use App\Models\Service;
use App\Models\ServiceOption;
use App\Notifications\CustomerCancelledNotification;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Notifications\Dispatcher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailer;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mime\Email;

// "Now" is Tuesday 27 October 2026, 08:00 UTC. With 1440 minutes of notice, the reference booking
// (Thursday 29 October, 08:00-10:45 UTC, 09:00-11:45 in Italy) can be cancelled by the customer.
beforeEach(function () {
    config()->set('booking.cancellation_notice_minutes', 1440);
    $this->travelTo(CarbonImmutable::parse('2026-10-27 08:00', 'UTC'));
    app()->setLocale('it');
});

/**
 * The array transport of the tests. Mail::mailer() is declared as the Mailer contract, which has
 * no getSymfonyTransport(): the assertions tell the editor the real types, and check them.
 */
function ccnTransport(): ArrayTransport
{
    $mailer = Mail::mailer();
    assert($mailer instanceof Mailer);
    $transport = $mailer->getSymfonyTransport();
    assert($transport instanceof ArrayTransport);

    return $transport;
}

/**
 * The email of a sent message. getOriginalMessage() is declared as RawMessage, which has no
 * subject nor recipients: the assertion tells the editor the real type, and checks it.
 */
function ccnEmail(SentMessage $sent): Email
{
    $email = $sent->getOriginalMessage();
    assert($email instanceof Email);

    return $email;
}

/**
 * The reference booking: "Treccine" by "Salone Awa", with the given fields replaced.
 *
 * @param  array<string, mixed>  $override
 */
function ccnBooking(BookingStatus $status = BookingStatus::Confirmed, array $override = []): Booking
{
    $provider = Provider::factory()->published()->create(['name' => 'Salone Awa']);
    $service = Service::factory()->for($provider)->create(['name' => 'Treccine']);

    return Booking::factory()->for($provider)->for($service)->create([
        'status' => $status,
        'starts_at' => CarbonImmutable::parse('2026-10-29 08:00', 'UTC'),
        'ends_at' => CarbonImmutable::parse('2026-10-29 10:45', 'UTC'),
        'customer_name' => 'Awa Diop',
        'customer_phone' => '+393331234567',
        'customer_email' => 'awa@example.com',
        'notes' => 'Nota segretissima del cliente',
        ...$override,
    ]);
}

/**
 * The customer cancels from the private page.
 */
function ccnCancel(Booking $booking): TestResponse
{
    return test()->post(route('bookings.cancel', ['booking' => $booking->public_token]));
}

function ccnMail(Booking $booking): MailMessage
{
    return (new CustomerCancelledNotification($booking))->toMail($booking->provider->user);
}

function ccnHtml(MailMessage $mail): string
{
    return (string) $mail->render();
}

function ccnText(MailMessage $mail): string
{
    return view($mail->view['text'], $mail->viewData)->render();
}

/**
 * The only message that reached the mail transport (sync queue and array mailer in the tests).
 */
function ccnSent(): SentMessage
{
    return ccnTransport()->messages()->sole();
}

test('a cancellation notifies only the user of that provider, once', function (BookingStatus $status) {
    Notification::fake();
    $booking = ccnBooking($status);
    $other = ccnBooking();

    ccnCancel($booking)->assertSessionHasNoErrors();

    expect($booking->fresh()?->status)->toBe(BookingStatus::Cancelled);
    Notification::assertSentToTimes($booking->provider->user, CustomerCancelledNotification::class, 1);
    Notification::assertSentTo($booking->provider->user, fn (CustomerCancelledNotification $notification) => $notification->booking->is($booking));
    Notification::assertNotSentTo($other->provider->user, CustomerCancelledNotification::class);
    Notification::assertCount(1);
})->with([
    'pending' => [BookingStatus::Pending],
    'confirmed' => [BookingStatus::Confirmed],
]);

test('the email is queued, not sent during the request', function () {
    Queue::fake();

    ccnCancel(ccnBooking())->assertSessionHasNoErrors();

    Queue::assertCount(1);
    Queue::assertPushed(SendQueuedNotifications::class, fn (SendQueuedNotifications $job) => $job->notification instanceof CustomerCancelledNotification);
    expect(ccnTransport()->messages())->toBeEmpty();
});

test('no email for a booking already cancelled, nor when it is too late', function () {
    Notification::fake();

    ccnCancel(ccnBooking(BookingStatus::Cancelled))->assertSessionHasNoErrors();
    // Starts in 4 hours: inside the 1440 minutes of notice.
    $late = ccnBooking(override: [
        'starts_at' => CarbonImmutable::parse('2026-10-27 12:00', 'UTC'),
        'ends_at' => CarbonImmutable::parse('2026-10-27 13:00', 'UTC'),
    ]);
    ccnCancel($late)->assertSessionHasErrors('cancel');

    expect($late->fresh()?->status)->toBe(BookingStatus::Confirmed);
    Notification::assertNothingSent();
});

test('a failure while queueing the email is reported and the booking stays cancelled', function () {
    Exceptions::fake();
    $this->mock(Dispatcher::class)
        ->shouldReceive('send')
        ->andThrow(new RuntimeException('Coda non disponibile'));
    $booking = ccnBooking();

    ccnCancel($booking)
        ->assertRedirect(route('bookings.show', ['booking' => $booking->public_token]))
        ->assertSessionHasNoErrors()
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => __('booking.cancel.cancelled')]);

    expect($booking->fresh()?->status)->toBe(BookingStatus::Cancelled);
    Exceptions::assertReported(fn (RuntimeException $exception) => $exception->getMessage() === 'Coda non disponibile');
});

test('subject, greeting and the local time, in winter and in summer', function (string $start, string $end, string $date) {
    $mail = ccnMail(ccnBooking(override: [
        'starts_at' => CarbonImmutable::parse($start, 'UTC'),
        'ends_at' => CarbonImmutable::parse($end, 'UTC'),
    ]));

    expect($mail->subject)->toBe('Prenotazione annullata dal cliente: Treccine');
    foreach ([ccnHtml($mail), ccnText($mail)] as $body) {
        expect($body)
            ->toContain('Ciao Salone Awa,')
            ->toContain('Treccine')
            ->toContain($date)
            ->toContain('09:00–11:45')
            ->toContain('Awa Diop')
            ->toContain('+393331234567')
            ->not->toContain('Opzioni');
    }
    // {{ }} escapes the apostrophe in the HTML version.
    expect(ccnText($mail))->toContain("Un cliente ha annullato una prenotazione. L'orario è di nuovo libero.");
    expect(ccnHtml($mail))->toContain('Un cliente ha annullato una prenotazione. L&#039;orario è di nuovo libero.');
})->with([
    'winter' => ['2026-10-29 08:00', '2026-10-29 10:45', 'giovedì 29 ottobre 2026'],
    'summer' => ['2027-07-06 07:00', '2027-07-06 09:45', 'martedì 6 luglio 2027'],
]);

test('the options appear only when there are some, ordered by id', function () {
    $booking = ccnBooking();
    $second = ServiceOption::factory()->for($booking->service)->create(['name' => 'Perline']);
    $first = ServiceOption::factory()->for($booking->service)->create(['name' => 'Extension']);
    ServiceOption::factory()->for($booking->service)->create(['name' => 'Non scelta']);
    $booking->options()->attach([$first->id, $second->id]);
    $mail = ccnMail($booking);

    expect(ccnText($mail))->toContain('Opzioni: Perline, Extension')->not->toContain('Non scelta');
    expect(ccnHtml($mail))->toContain('Perline, Extension');
});

test('no notes, no token and no private address, in subject, HTML, text and sent message', function () {
    $booking = ccnBooking();

    ccnCancel($booking)->assertSessionHasNoErrors();
    $mail = ccnMail($booking);
    $sent = ccnSent();
    $email = ccnEmail($sent);

    foreach ([$mail->subject, ccnHtml($mail), ccnText($mail), $sent->toString(), (string) $email->getTextBody(), (string) $email->getHtmlBody()] as $part) {
        expect($part)
            ->not->toContain($booking->public_token)
            ->not->toContain('/bookings/')
            ->not->toContain('segretissima');
    }
});

test('the phone is plain text, not a link', function () {
    expect(ccnHtml(ccnMail(ccnBooking())))->toContain('+393331234567')->not->toContain('tel:')->not->toContain('<a');
});

test('what the customer wrote is shown as text, never as Markdown or HTML', function () {
    $mail = ccnMail(ccnBooking(override: [
        'customer_name' => '<script>alert(1)</script> [Mario](https://evil.example/phish) **grassetto**',
    ]));
    $html = ccnHtml($mail);

    expect($html)
        ->not->toContain('<script>')
        ->not->toMatch('/<a\s[^>]*evil\.example/')
        ->not->toContain('<strong>')
        ->toContain('&lt;script&gt;alert(1)&lt;/script&gt; [Mario](https://evil.example/phish) **grassetto**');
    expect(ccnText($mail))->toContain('<script>alert(1)</script> [Mario](https://evil.example/phish) **grassetto**');
    expect($mail->subject)->toBe('Prenotazione annullata dal cliente: Treccine');
});

test('a line break in the customer name does not change the subject or the headers', function () {
    $booking = ccnBooking(override: ['customer_name' => "Mario\nBcc: x@example.com"]);

    ccnCancel($booking)->assertSessionHasNoErrors();
    $sent = ccnSent();
    $email = ccnEmail($sent);
    [$headers] = explode("\r\n\r\n", $sent->toString(), 2);

    expect($email->getSubject())->toBe('Prenotazione annullata dal cliente: Treccine');
    expect($email->getTo()[0]->getAddress())->toBe($booking->provider->user->email);
    expect($email->getBcc())->toBeEmpty();
    expect($headers)->not->toContain('x@example.com');
});

test('mail channel only, three attempts and the backoff', function () {
    $notification = new CustomerCancelledNotification(ccnBooking());

    expect($notification)->toBeInstanceOf(ShouldQueue::class);
    expect($notification->via($notification->booking->provider->user))->toBe(['mail']);
    expect($notification->tries)->toBe(3);
    expect($notification->backoff())->toBe([60, 300]);
    expect($notification->deleteWhenMissingModels)->toBeTrue();
});

test('a job for a booking deleted in the meantime is discarded without failing', function () {
    // A real queue: the job is stored in the jobs table and run by the worker below.
    config(['queue.default' => 'database']);
    $booking = ccnBooking();
    ccnCancel($booking)->assertSessionHasNoErrors();
    expect(DB::table('jobs')->count())->toBe(1);

    $booking->delete();
    $this->artisan('queue:work', ['--once' => true, '--stop-when-empty' => true])->assertSuccessful();

    expect(DB::table('jobs')->count())->toBe(0);
    expect(DB::table('failed_jobs')->count())->toBe(0);
    expect(ccnTransport()->messages())->toBeEmpty();
});

test('the Italian texts are exactly the expected ones', function () {
    $texts = [
        'booking.cancelled_notice.subject' => 'Prenotazione annullata dal cliente: :service',
        'booking.cancelled_notice.greeting' => 'Ciao :name,',
        'booking.cancelled_notice.intro' => "Un cliente ha annullato una prenotazione. L'orario è di nuovo libero.",
        'booking.cancelled_notice.service' => 'Servizio',
        'booking.cancelled_notice.options' => 'Opzioni',
        'booking.cancelled_notice.when' => 'Quando',
        'booking.cancelled_notice.customer' => 'Cliente',
        'booking.cancelled_notice.phone' => 'Telefono',
    ];

    foreach ($texts as $key => $text) {
        expect(__($key))->toBe($text);
    }
});
