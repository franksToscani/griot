<?php

use App\Enums\Weekday;
use App\Models\Availability;
use App\Models\Booking;
use App\Models\Provider;
use App\Models\Service;
use App\Models\ServiceOption;
use App\Notifications\NewBookingNotification;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Notifications\Dispatcher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\Mailer\SentMessage;

// "Now" is Sunday 18 October 2026, 08:00 UTC. The form books Monday 19 October at 09:00 local
// (07:00 UTC), inside the 09:00-13:00 window of the provider.
beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-18 08:00', 'UTC'));
    app()->setLocale('it');
});

function nbProvider(): Provider
{
    $provider = Provider::factory()->published()->create(['name' => 'Salone Awa']);
    Availability::factory()->for($provider)->create([
        'weekday' => Weekday::Monday,
        'start_time' => '09:00:00',
        'end_time' => '13:00:00',
    ]);

    return $provider;
}

/**
 * Send a valid booking form to the provider's page, with the given fields replaced.
 *
 * @param  array<string, mixed>  $override
 */
function nbPost(Provider $provider, array $override = [])
{
    $service = Service::factory()->for($provider)->create(['duration_min' => 60, 'duration_max' => 60]);

    return test()->post(route('providers.bookings.store', $provider->slug), [
        'service' => $service->id,
        'starts_at' => '2026-10-19T07:00:00Z',
        'customer_name' => 'Awa Diop',
        'customer_phone' => '+39 333 123 4567',
        ...$override,
    ]);
}

/**
 * The reference booking: "Treccine" by "Salone Awa", Thursday 29 October 2026 from 08:00 to
 * 10:45 UTC (09:00-11:45 in Italy, winter time), with the given fields replaced.
 *
 * @param  array<string, mixed>  $override
 */
function nbBooking(array $override = []): Booking
{
    $provider = Provider::factory()->published()->create(['name' => 'Salone Awa']);
    $service = Service::factory()->for($provider)->create(['name' => 'Treccine']);

    return Booking::factory()->for($provider)->for($service)->create([
        'starts_at' => CarbonImmutable::parse('2026-10-29 08:00', 'UTC'),
        'ends_at' => CarbonImmutable::parse('2026-10-29 10:45', 'UTC'),
        'customer_name' => 'Awa Diop',
        'customer_phone' => '+39 333 123 4567',
        'customer_email' => null,
        'notes' => null,
        ...$override,
    ]);
}

function nbMail(Booking $booking): MailMessage
{
    return (new NewBookingNotification($booking))->toMail($booking->provider->user);
}

function nbHtml(MailMessage $mail): string
{
    return (string) $mail->render();
}

function nbText(MailMessage $mail): string
{
    return view($mail->view['text'], $mail->viewData)->render();
}

/**
 * Send the notification for real (sync queue and array mailer in the tests) and return the
 * message that reached the mail transport.
 */
function nbSend(Booking $booking): SentMessage
{
    $booking->provider->user->notify(new NewBookingNotification($booking));

    return Mail::mailer()->getSymfonyTransport()->messages()->sole();
}

test('a valid form notifies only the user of that provider, once', function () {
    Notification::fake();
    $provider = nbProvider();
    $otherProvider = nbProvider();

    nbPost($provider)->assertSessionHasNoErrors();
    $booking = Booking::query()->sole();

    Notification::assertSentToTimes($provider->user, NewBookingNotification::class, 1);
    Notification::assertSentTo($provider->user, fn (NewBookingNotification $notification) => $notification->booking->is($booking));
    Notification::assertNotSentTo($otherProvider->user, NewBookingNotification::class);
    Notification::assertCount(1);
});

test('the email is queued, not sent during the request', function () {
    Queue::fake();

    nbPost(nbProvider())->assertSessionHasNoErrors();

    Queue::assertCount(1);
    Queue::assertPushed(SendQueuedNotifications::class, fn (SendQueuedNotifications $job) => $job->notification instanceof NewBookingNotification);
    expect(Mail::mailer()->getSymfonyTransport()->messages())->toBeEmpty();
});

test('no notification for an invalid form, a taken time or an unpublished provider', function () {
    Notification::fake();

    $provider = nbProvider();
    nbPost($provider, ['customer_name' => ''])->assertSessionHasErrors('customer_name');

    $taken = nbProvider();
    Booking::factory()->for($taken)->create([
        'starts_at' => CarbonImmutable::parse('2026-10-19 07:00', 'UTC'),
        'ends_at' => CarbonImmutable::parse('2026-10-19 08:00', 'UTC'),
    ]);
    nbPost($taken)->assertSessionHasErrors('starts_at');

    $unpublished = nbProvider();
    $unpublished->update(['is_published' => false]);
    nbPost($unpublished)->assertNotFound();

    Notification::assertNothingSent();
});

test('a failure while queueing the email is reported and the booking stays', function () {
    Exceptions::fake();
    $this->mock(Dispatcher::class)
        ->shouldReceive('send')
        ->andThrow(new RuntimeException('Coda non disponibile'));

    $response = nbPost(nbProvider());
    $booking = Booking::query()->sole();

    $response
        ->assertRedirect(route('bookings.show', ['booking' => $booking->public_token]))
        ->assertSessionHasNoErrors()
        ->assertInertiaFlash('toast', [
            'type' => 'success',
            'message' => 'Richiesta inviata. Il professionista deve ancora confermarla.',
        ]);
    Exceptions::assertReported(fn (RuntimeException $exception) => $exception->getMessage() === 'Coda non disponibile');
    $this->get($response->headers->get('Location'))->assertOk();
});

test('subject, greeting and the local time, in winter and in summer', function (string $start, string $end, string $date) {
    $mail = nbMail(nbBooking([
        'starts_at' => CarbonImmutable::parse($start, 'UTC'),
        'ends_at' => CarbonImmutable::parse($end, 'UTC'),
    ]));

    expect($mail->subject)->toBe('Nuova richiesta di prenotazione: Treccine');
    expect($mail->subject)->not->toContain('Awa Diop');

    foreach ([nbHtml($mail), nbText($mail)] as $body) {
        expect($body)
            ->toContain('Ciao Salone Awa,')
            ->toContain('Hai ricevuto una nuova richiesta di prenotazione. È in attesa della tua conferma.')
            ->toContain('Treccine')
            ->toContain($date)
            ->toContain('09:00–11:45')
            ->toContain('Awa Diop')
            ->toContain('+39 333 123 4567')
            ->not->toContain('€');
    }
})->with([
    'winter' => ['2026-10-29 08:00', '2026-10-29 10:45', 'giovedì 29 ottobre 2026'],
    'summer' => ['2026-07-06 07:00', '2026-07-06 09:45', 'lunedì 6 luglio 2026'],
]);

test('options, email and notes appear only when there are some', function () {
    $empty = nbMail(nbBooking());

    foreach ([nbHtml($empty), nbText($empty)] as $body) {
        expect($body)->not->toContain('Opzioni')->not->toContain('Email')->not->toContain('Note');
    }

    $booking = nbBooking(['customer_email' => 'awa@example.com', 'notes' => 'Porto io le extension.']);
    $second = ServiceOption::factory()->for($booking->service)->create(['name' => 'Perline']);
    $first = ServiceOption::factory()->for($booking->service)->create(['name' => 'Extension']);
    ServiceOption::factory()->for($booking->service)->create(['name' => 'Non scelta']);
    $booking->options()->attach([$first->id, $second->id]);
    $full = nbMail($booking);

    // Separated by a comma, ordered by id.
    expect(nbText($full))
        ->toContain('Opzioni: Perline, Extension')
        ->toContain('Email: awa@example.com')
        ->toContain("Note:\nPorto io le extension.")
        ->not->toContain('Non scelta');
    expect(nbHtml($full))
        ->toContain('Perline, Extension')
        ->toContain('awa@example.com')
        ->toContain('Porto io le extension.');
});

test('the phone is plain text, not a link', function () {
    $html = nbHtml(nbMail(nbBooking()));

    expect($html)->toContain('+39 333 123 4567')->not->toContain('tel:')->not->toContain('<a');
});

test('the token and the private address are not in the email', function () {
    $booking = nbBooking(['customer_email' => 'awa@example.com', 'notes' => 'Nota']);
    $mail = nbMail($booking);
    $sent = nbSend($booking);

    foreach ([$mail->subject, nbHtml($mail), nbText($mail), $sent->toString()] as $part) {
        expect($part)->not->toContain($booking->public_token)->not->toContain('/bookings/');
    }
});

test('what the customer wrote is shown as text, never as Markdown or HTML', function () {
    $mail = nbMail(nbBooking([
        'customer_name' => '**Mario**',
        'notes' => "[clicca qui](https://evil.example/phish)\n<script>alert(1)</script>",
    ]));
    $html = nbHtml($mail);

    expect($html)
        ->not->toMatch('/<a\s[^>]*evil\.example/')
        ->not->toContain('<script>')
        ->not->toContain('<strong>')
        // Still readable: escaped, with the line break kept.
        ->toContain('**Mario**')
        ->toContain('[clicca qui](https://evil.example/phish)<br />')
        ->toContain('&lt;script&gt;alert(1)&lt;/script&gt;');
    expect(nbText($mail))->toContain("[clicca qui](https://evil.example/phish)\n<script>alert(1)</script>");
    expect($mail->subject)->toBe('Nuova richiesta di prenotazione: Treccine');
});

test('a line break in the customer name does not change the subject or the headers', function () {
    $sent = nbSend(nbBooking(['customer_name' => "Mario\nBcc: x@example.com"]));
    $email = $sent->getOriginalMessage();
    [$headers] = explode("\r\n\r\n", $sent->toString(), 2);

    expect($email->getSubject())->toBe('Nuova richiesta di prenotazione: Treccine');
    expect($email->getBcc())->toBeEmpty();
    expect($email->getHeaders()->has('Bcc'))->toBeFalse();
    expect($headers)->not->toContain('x@example.com');
});

test('mail channel only, three attempts and the backoff', function () {
    $notification = new NewBookingNotification(nbBooking());

    expect($notification)->toBeInstanceOf(ShouldQueue::class);
    expect($notification->via($notification->booking->provider->user))->toBe(['mail']);
    expect($notification->tries)->toBe(3);
    expect($notification->backoff())->toBe([60, 300]);
    expect($notification->deleteWhenMissingModels)->toBeTrue();
});

test('a job for a booking deleted in the meantime is discarded without failing', function () {
    // A real queue: the job is stored in the jobs table and run by the worker below.
    config(['queue.default' => 'database']);
    $booking = nbBooking();
    $booking->provider->user->notify(new NewBookingNotification($booking));
    expect(DB::table('jobs')->count())->toBe(1);

    $booking->delete();
    $this->artisan('queue:work', ['--once' => true, '--stop-when-empty' => true])->assertSuccessful();

    expect(DB::table('jobs')->count())->toBe(0);
    expect(DB::table('failed_jobs')->count())->toBe(0);
    expect(Mail::mailer()->getSymfonyTransport()->messages())->toBeEmpty();
});

test('the Italian texts are exactly the expected ones', function () {
    expect(__('booking.notification.subject'))->toBe('Nuova richiesta di prenotazione: :service');
    expect(__('booking.notification.greeting'))->toBe('Ciao :name,');
    expect(__('booking.notification.intro'))->toBe('Hai ricevuto una nuova richiesta di prenotazione. È in attesa della tua conferma.');
    expect(__('booking.notification.service'))->toBe('Servizio');
    expect(__('booking.notification.options'))->toBe('Opzioni');
    expect(__('booking.notification.when'))->toBe('Quando');
    expect(__('booking.notification.customer'))->toBe('Cliente');
    expect(__('booking.notification.phone'))->toBe('Telefono');
    expect(__('booking.notification.email'))->toBe('Email');
    expect(__('booking.notification.notes'))->toBe('Note');
    expect(__('booking.notification.outro'))->toBe("Contatta il cliente per confermare l'orario.");
});

test('the number of queries to send the email does not grow with the options', function () {
    $withoutOptions = nbBooking();
    $withOptions = nbBooking();
    $withOptions->options()->attach(ServiceOption::factory()->count(3)->for($withOptions->service)->create());
    // The users are read here, so that only the sending is counted.
    $firstUser = $withoutOptions->provider->user;
    $secondUser = $withOptions->provider->user;

    $queries = 0;
    DB::listen(function () use (&$queries) {
        $queries++;
    });

    // The sync queue of the tests serializes the job and runs it at once: the count includes
    // reloading booking and user, then provider, service and options in toMail().
    $firstUser->notify(new NewBookingNotification($withoutOptions));
    $countWithout = $queries;

    $queries = 0;
    $secondUser->notify(new NewBookingNotification($withOptions));

    expect($queries)->toBe($countWithout);
});
