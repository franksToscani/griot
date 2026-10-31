<?php

use App\Booking\BookingOutcome;
use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Provider;
use App\Models\Service;
use App\Notifications\BookingStatusNotification;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Notifications\Dispatcher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Mail\Mailer;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mime\Email;

// "Now" is Tuesday 27 October 2026, 08:00 UTC. The bookings are on Thursday 29 October from 08:00
// to 10:45 UTC: 09:00-11:45 in Italy, winter time.
beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-27 08:00', 'UTC'));
    app()->setLocale('it');
});

/**
 * The array transport of the tests. Mail::mailer() is declared as the Mailer contract, which has
 * no getSymfonyTransport(): the assertions tell the editor the real types, and check them.
 */
function bsnTransport(): ArrayTransport
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
function bsnEmail(SentMessage $sent): Email
{
    $email = $sent->getOriginalMessage();
    assert($email instanceof Email);

    return $email;
}

/**
 * A booking of "Treccine" by "Salone Awa", with the given fields replaced.
 *
 * @param  array<string, mixed>  $override
 */
function bsnBooking(BookingStatus $status = BookingStatus::Pending, array $override = []): Booking
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
        ...$override,
    ]);
}

/**
 * The provider of the booking sends one of the three actions.
 *
 * @param  'confirm'|'reject'|'cancel'  $action
 * @param  array<string, mixed>  $data
 */
function bsnSend(Booking $booking, string $action, array $data = []): TestResponse
{
    return test()->actingAs($booking->provider->user)->post(route("provider.bookings.{$action}", ['booking' => $booking->id]), $data);
}

function bsnMail(Booking $booking, BookingOutcome $outcome): MailMessage
{
    return (new BookingStatusNotification($booking, $outcome))->toMail(new AnonymousNotifiable);
}

function bsnHtml(MailMessage $mail): string
{
    return (string) $mail->render();
}

function bsnText(MailMessage $mail): string
{
    return view($mail->view['text'], $mail->viewData)->render();
}

/**
 * The messages that reached the mail transport (sync queue and array mailer in the tests).
 *
 * @return list<SentMessage>
 */
function bsnSent(): array
{
    return array_values(iterator_to_array(bsnTransport()->messages()));
}

test('each action notifies the customer\'s email once, with its outcome', function (BookingStatus $status, string $action, BookingOutcome $outcome) {
    Notification::fake();
    $booking = bsnBooking($status);

    bsnSend($booking, $action)->assertSessionHasNoErrors();

    Notification::assertSentOnDemandTimes(BookingStatusNotification::class, 1);
    Notification::assertSentOnDemand(
        BookingStatusNotification::class,
        fn (BookingStatusNotification $notification, array $channels, AnonymousNotifiable $notifiable) => $notification->booking->is($booking)
            && $notification->outcome === $outcome
            && $notifiable->routes === ['mail' => 'awa@example.com'],
    );
    Notification::assertCount(1);
})->with([
    'confirm' => [BookingStatus::Pending, 'confirm', BookingOutcome::Confirmed],
    'reject' => [BookingStatus::Pending, 'reject', BookingOutcome::Rejected],
    'cancel' => [BookingStatus::Confirmed, 'cancel', BookingOutcome::Cancelled],
]);

test('the email is queued, not sent during the request', function () {
    Queue::fake();

    bsnSend(bsnBooking(), 'confirm')->assertSessionHasNoErrors();

    Queue::assertCount(1);
    Queue::assertPushed(SendQueuedNotifications::class, fn (SendQueuedNotifications $job) => $job->notification instanceof BookingStatusNotification);
    expect(bsnSent())->toBeEmpty();
});

test('no email without an address, and the action is done anyway', function () {
    Notification::fake();
    $booking = bsnBooking(override: ['customer_email' => null]);

    bsnSend($booking, 'confirm')->assertSessionHasNoErrors();

    expect($booking->fresh()?->status)->toBe(BookingStatus::Confirmed);
    Notification::assertNothingSent();
});

test('no email when the rules refuse the action', function (BookingStatus $status, string $action) {
    Notification::fake();
    $booking = bsnBooking($status);

    bsnSend($booking, $action)->assertSessionHasErrors('booking');

    expect($booking->fresh()?->status)->toBe($status);
    Notification::assertNothingSent();
})->with([
    'confirm a cancelled one' => [BookingStatus::Cancelled, 'confirm'],
    'reject a confirmed one' => [BookingStatus::Confirmed, 'reject'],
    'cancel a pending one' => [BookingStatus::Pending, 'cancel'],
]);

test('no email when the reason is not valid', function () {
    Notification::fake();
    $booking = bsnBooking();

    bsnSend($booking, 'reject', ['reason' => str_repeat('x', 501)])->assertSessionHasErrors('reason');

    Notification::assertNothingSent();
});

test('a failure while queueing the email is reported and the action stays done', function () {
    Exceptions::fake();
    $this->mock(Dispatcher::class)
        ->shouldReceive('send')
        ->andThrow(new RuntimeException('Coda non disponibile'));
    $booking = bsnBooking();

    bsnSend($booking, 'reject', ['reason' => 'Sono malata'])
        ->assertRedirect(route('provider.bookings.index'))
        ->assertSessionHasNoErrors();

    expect($booking->fresh()?->status)->toBe(BookingStatus::Cancelled);
    expect($booking->fresh()?->cancellation_reason)->toBe('Sono malata');
    Exceptions::assertReported(fn (RuntimeException $exception) => $exception->getMessage() === 'Coda non disponibile');
});

test('subject and body of each outcome, with the local time and the private address', function (BookingOutcome $outcome, string $subject, string $sentence) {
    $booking = bsnBooking();
    $mail = bsnMail($booking, $outcome);
    $url = route('bookings.show', ['booking' => $booking->public_token]);

    expect($mail->subject)->toBe($subject);
    foreach ([bsnHtml($mail), bsnText($mail)] as $body) {
        expect($body)
            ->toContain('Ciao Awa Diop,')
            ->toContain($sentence)
            ->toContain('Treccine')
            ->toContain('giovedì 29 ottobre 2026')
            ->toContain('09:00–11:45')
            ->toContain('Puoi rivedere la prenotazione qui:')
            ->toContain($url)
            // No reason was written.
            ->not->toContain('Motivo');
    }
})->with([
    'confirmed' => [BookingOutcome::Confirmed, 'Prenotazione confermata: Treccine', 'Salone Awa ha confermato la tua prenotazione.'],
    'rejected' => [BookingOutcome::Rejected, 'Richiesta non accettata: Treccine', 'Salone Awa non può accettare la tua richiesta.'],
    'cancelled' => [BookingOutcome::Cancelled, 'Prenotazione annullata: Treccine', 'Salone Awa ha annullato la tua prenotazione.'],
]);

test('the reason appears only for a rejection or a cancellation that has one', function () {
    $booking = bsnBooking(BookingStatus::Cancelled, ['cancellation_reason' => "Chiusura imprevista\nCi scusiamo"]);

    foreach ([BookingOutcome::Rejected, BookingOutcome::Cancelled] as $outcome) {
        $mail = bsnMail($booking, $outcome);
        expect(bsnHtml($mail))->toContain('Motivo')->toContain('Chiusura imprevista<br />');
        expect(bsnText($mail))->toContain("Motivo:\nChiusura imprevista\nCi scusiamo");
    }

    // A confirmation never shows a reason, even if the column had one.
    $confirmed = bsnMail($booking, BookingOutcome::Confirmed);
    expect(bsnHtml($confirmed))->not->toContain('Chiusura');
    expect(bsnText($confirmed))->not->toContain('Chiusura');
});

test('the token is not in the subject nor in the logs', function () {
    $logged = [];
    Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$logged) {
        $logged[] = $event->message.' '.json_encode($event->context);
    });
    // The mail transport "log" writes the whole email in the log: here the array transport is used,
    // and a test line checks that the listener does see what is logged.
    Log::info('controllo del listener');
    $booking = bsnBooking();

    bsnSend($booking, 'reject', ['reason' => 'Motivo qualunque'])->assertSessionHasNoErrors();
    $sent = bsnSent();

    expect($sent)->toHaveCount(1);
    expect(bsnEmail($sent[0])->getSubject())->toBe('Richiesta non accettata: Treccine');
    [$headers] = explode("\r\n\r\n", $sent[0]->toString(), 2);
    expect($headers)->not->toContain($booking->public_token);
    // In the body, yes: it is the customer's link. Read before the encoding, which may cut it.
    expect((string) bsnEmail($sent[0])->getTextBody())->toContain($booking->public_token);
    expect($logged)->toHaveCount(1);
    expect(implode("\n", $logged))->not->toContain($booking->public_token);
});

test('what the customer and the provider wrote is shown as text, never as Markdown or HTML', function () {
    $booking = bsnBooking(BookingStatus::Cancelled, [
        'customer_name' => '<script>alert(1)</script> [Mario](https://evil.example/a)',
        'cancellation_reason' => "[clicca qui](https://evil.example/phish)\n<script>alert(2)</script>",
    ]);
    $booking->provider->forceFill(['name' => '**Salone** <b>Awa</b>'])->save();
    $mail = bsnMail($booking->fresh() ?? $booking, BookingOutcome::Cancelled);
    $html = bsnHtml($mail);

    expect($html)
        ->not->toMatch('/<a\s[^>]*evil\.example/')
        ->not->toContain('<script>')
        ->not->toContain('<b>Awa</b>')
        ->not->toContain('<strong>')
        // Still readable: escaped, with the line break kept.
        ->toContain('&lt;script&gt;alert(1)&lt;/script&gt; [Mario](https://evil.example/a)')
        ->toContain('**Salone** &lt;b&gt;Awa&lt;/b&gt; ha annullato la tua prenotazione.')
        ->toContain('[clicca qui](https://evil.example/phish)<br />')
        ->toContain('&lt;script&gt;alert(2)&lt;/script&gt;');
    // The only link is the private address.
    expect(preg_match_all('/<a\s/', $html))->toBe(1);
    expect(bsnText($mail))->toContain("[clicca qui](https://evil.example/phish)\n<script>alert(2)</script>");
    expect($mail->subject)->toBe('Prenotazione annullata: Treccine');
});

test('a line break in the customer name does not change the subject or the headers', function () {
    $booking = bsnBooking(override: ['customer_name' => "Mario\nBcc: x@example.com"]);

    bsnSend($booking, 'confirm')->assertSessionHasNoErrors();
    $sent = bsnSent()[0];
    $email = bsnEmail($sent);
    [$headers] = explode("\r\n\r\n", $sent->toString(), 2);

    expect($email->getSubject())->toBe('Prenotazione confermata: Treccine');
    expect($email->getBcc())->toBeEmpty();
    expect($headers)->not->toContain('x@example.com');
});

test('the outcome is the one of the answer, even if the booking changes before the job runs', function () {
    // A real queue: the job is stored in the jobs table and run by the worker below.
    config(['queue.default' => 'database']);
    $booking = bsnBooking();

    bsnSend($booking, 'confirm')->assertSessionHasNoErrors();
    // The customer cancels before the worker runs.
    $this->post(route('bookings.cancel', ['booking' => $booking->public_token]))->assertSessionHasNoErrors();
    // Every job, in any order: the customer's cancellation also queues an email to the provider.
    $this->artisan('queue:work', ['--stop-when-empty' => true])->assertSuccessful();

    $toCustomer = array_values(array_filter(
        bsnSent(),
        fn (SentMessage $sent) => $sent->getEnvelope()->getRecipients()[0]->getAddress() === 'awa@example.com',
    ));
    expect($toCustomer)->toHaveCount(1);
    expect(bsnEmail($toCustomer[0])->getSubject())->toBe('Prenotazione confermata: Treccine');
});

test('mail channel only, three attempts and the backoff', function () {
    $notification = new BookingStatusNotification(bsnBooking(), BookingOutcome::Confirmed);

    expect($notification)->toBeInstanceOf(ShouldQueue::class);
    expect($notification->via(new AnonymousNotifiable))->toBe(['mail']);
    expect($notification->tries)->toBe(3);
    expect($notification->backoff())->toBe([60, 300]);
    expect($notification->deleteWhenMissingModels)->toBeTrue();
});

test('a job for a booking deleted in the meantime is discarded without failing', function () {
    config(['queue.default' => 'database']);
    $booking = bsnBooking(BookingStatus::Confirmed);
    BookingStatusNotification::sendFor($booking, BookingOutcome::Confirmed);
    expect(DB::table('jobs')->count())->toBe(1);

    $booking->delete();
    $this->artisan('queue:work', ['--once' => true, '--stop-when-empty' => true])->assertSuccessful();

    expect(DB::table('jobs')->count())->toBe(0);
    expect(DB::table('failed_jobs')->count())->toBe(0);
    expect(bsnSent())->toBeEmpty();
});

test('the Italian texts are exactly the expected ones', function () {
    $texts = [
        'booking.notice.subject_confirmed' => 'Prenotazione confermata: :service',
        'booking.notice.subject_rejected' => 'Richiesta non accettata: :service',
        'booking.notice.subject_cancelled' => 'Prenotazione annullata: :service',
        'booking.notice.greeting' => 'Ciao :name,',
        'booking.notice.confirmed' => ':provider ha confermato la tua prenotazione.',
        'booking.notice.rejected' => ':provider non può accettare la tua richiesta.',
        'booking.notice.cancelled' => ':provider ha annullato la tua prenotazione.',
        'booking.notice.when' => 'Quando',
        'booking.notice.reason' => 'Motivo',
        'booking.notice.link' => 'Puoi rivedere la prenotazione qui:',
    ];

    foreach ($texts as $key => $text) {
        expect(__($key))->toBe($text);
    }
});
