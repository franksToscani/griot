<?php

use App\Enums\Weekday;
use App\Models\Availability;
use App\Models\Booking;
use App\Models\Provider;
use App\Models\Service;
use App\Models\ServiceOption;
use App\Notifications\BookingReceivedNotification;
use App\Notifications\NewBookingNotification;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Notifications\Dispatcher;
use Illuminate\Mail\Mailer;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\Mime\Email;

// "Now" is Sunday 18 October 2026, 08:00 UTC. The forms book Monday 19 October at 09:00 local
// (07:00 UTC, summer time), inside the 09:00-13:00 window of the provider.
beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-18 08:00', 'UTC'));
    app()->setLocale('it');
});

const BR_EMAIL = 'awa@example.test';

function brProvider(): Provider
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
 * The public booking form of a provider, valid, with an email, with the given fields replaced.
 *
 * @param  array<string, mixed>  $override
 */
function brPost(Provider $provider, array $override = [])
{
    $service = Service::factory()->for($provider)->create(['duration_min' => 60, 'duration_max' => 60]);

    return test()->post(route('providers.bookings.store', $provider->slug), [
        'service' => $service->id,
        'starts_at' => '2026-10-19T07:00:00Z',
        'customer_name' => 'Awa Diop',
        'customer_phone' => '+39 333 123 4567',
        'customer_email' => BR_EMAIL,
        ...$override,
    ]);
}

/**
 * The reference booking: "Treccine" (87,65 €) with the option "Perline" (+4,35 €) by "Salone Awa",
 * Thursday 29 October 2026 from 08:00 to 10:45 UTC (09:00-11:45 in Italy, winter time).
 *
 * @param  array<string, mixed>  $override
 */
function brBooking(array $override = []): Booking
{
    $provider = Provider::factory()->published()->create(['name' => 'Salone Awa']);
    $service = Service::factory()->for($provider)->create(['name' => 'Treccine', 'price' => '87.65']);
    $option = ServiceOption::factory()->for($service)->create(['name' => 'Perline', 'extra_price' => '4.35']);

    $booking = Booking::factory()->for($provider)->for($service)->create([
        'starts_at' => CarbonImmutable::parse('2026-10-29 08:00', 'UTC'),
        'ends_at' => CarbonImmutable::parse('2026-10-29 10:45', 'UTC'),
        'customer_name' => 'Awa Diop',
        'customer_phone' => '+39 333 123 4567',
        'customer_email' => BR_EMAIL,
        'notes' => 'Capelli lunghi',
        'locale' => 'it',
        ...$override,
    ]);
    attachBookingOptions($booking, $option);

    return $booking;
}

function brMail(Booking $booking): MailMessage
{
    return (new BookingReceivedNotification($booking))->toMail(new AnonymousNotifiable);
}

/**
 * The HTML and the text version of the email, together.
 */
function brBody(MailMessage $mail): string
{
    return (string) $mail->render()."\n".view($mail->view['text'], $mail->viewData)->render();
}

/**
 * The emails sent for real (sync queue and array mailer in the tests).
 *
 * @return list<Email>
 */
function brSent(): array
{
    $mailer = Mail::mailer();
    assert($mailer instanceof Mailer);
    $transport = $mailer->getSymfonyTransport();
    assert($transport instanceof ArrayTransport);

    return array_values(array_map(function ($sent) {
        $email = $sent->getOriginalMessage();
        assert($email instanceof Email);

        return $email;
    }, iterator_to_array($transport->messages())));
}

// ---------------------------------------------------------------------------------------------
// When it is sent
// ---------------------------------------------------------------------------------------------

test('a valid form with an email notifies that address once, after the provider', function () {
    Notification::fake();

    brPost(brProvider())->assertSessionHasNoErrors();
    $booking = Booking::query()->sole();

    Notification::assertSentOnDemandTimes(BookingReceivedNotification::class, 1);
    Notification::assertSentOnDemand(
        BookingReceivedNotification::class,
        fn (BookingReceivedNotification $notification, array $channels, AnonymousNotifiable $notifiable) => $notification->booking->is($booking)
            && $channels === ['mail']
            && $notifiable->routes === ['mail' => BR_EMAIL],
    );
    Notification::assertSentTo($booking->provider->user, NewBookingNotification::class);
    Notification::assertCount(2);
});

test('no email to the customer without an address', function () {
    Notification::fake();
    Exceptions::fake();

    brPost(brProvider(), ['customer_email' => null])->assertSessionHasNoErrors();

    Notification::assertSentOnDemandTimes(BookingReceivedNotification::class, 0);
    Notification::assertCount(1);
    // Skipped on purpose, not by an error caught on the way.
    Exceptions::assertNothingReported();
});

test('no email to the customer when the honeypot is filled', function () {
    Notification::fake();

    brPost(brProvider(), ['confirm_reference' => 'x'])->assertSessionHasNoErrors();

    expect(Booking::query()->count())->toBe(0);
    Notification::assertNothingSent();
});

test('no email to the customer for a booking the provider writes in the own agenda', function () {
    Notification::fake();
    $provider = brProvider();
    $service = Service::factory()->for($provider)->create(['duration_min' => 60, 'duration_max' => 60]);

    $this->actingAs($provider->user)->post(route('provider.bookings.store'), [
        'service' => $service->id,
        'starts_at' => '2026-10-19T07:00:00Z',
        'customer_name' => 'Awa Diop',
        'customer_phone' => '+39 333 123 4567',
        'customer_email' => BR_EMAIL,
    ])->assertSessionHasNoErrors();

    expect(Booking::query()->sole()->customer_email)->toBe(BR_EMAIL);
    Notification::assertSentOnDemandTimes(BookingReceivedNotification::class, 0);
});

test('the email is in the language of the booking, Italian when that one is not written', function (string $locale, string $expected, string $subject) {
    $booking = brBooking(['locale' => $locale]);

    BookingReceivedNotification::sendFor($booking);

    $email = collect(brSent())->sole();
    expect($email->getSubject())->toBe($subject)
        ->and((string) $email->getHtmlBody())->toContain('<html lang="'.$expected.'">');
})->with([
    'French' => ['fr', 'fr', "Demande envoyée\u{00A0}: Treccine"],
    'English' => ['en', 'en', 'Request sent: Treccine'],
    'Italian' => ['it', 'it', 'Richiesta inviata: Treccine'],
    'a language without texts' => ['de', 'it', 'Richiesta inviata: Treccine'],
]);

// ---------------------------------------------------------------------------------------------
// What it says
// ---------------------------------------------------------------------------------------------

test('the private link and the warning are in the HTML and in the text', function () {
    $booking = brBooking();
    $mail = brMail($booking);
    $url = route('bookings.show', ['booking' => $booking->public_token]);
    $warning = 'Chiunque abbia questo link può vedere e annullare la prenotazione. Non inoltrare questa email.';

    $html = (string) $mail->render();
    $text = view($mail->view['text'], $mail->viewData)->render();

    expect($url)->toStartWith('http')
        ->and($html)->toContain('<a href="'.e($url).'">'.e($url).'</a>')
        ->and(substr_count($html, '<a '))->toBe(1)
        ->and($html)->toContain($warning)
        ->and($text)->toContain("Puoi rivedere la prenotazione qui:\n".$url."\n")
        ->and($text)->toContain($warning)
        // The warning comes after the link.
        ->and(strpos($text, $warning))->toBeGreaterThan(strpos($text, $url));
});

test('greeting, provider, service, options, local time, saved price and notes', function () {
    $booking = brBooking();
    // A price changed after the booking: the email keeps the saved one.
    $booking->service->update(['price' => '120.00']);

    $body = brBody(brMail($booking));

    expect($body)->toContain('Ciao Awa Diop,')
        ->toContain('Richiesta inviata a Salone Awa: ti scriveremo quando risponde.')
        ->toContain('Treccine')
        ->toContain('Perline')
        ->toContain('giovedì 29 ottobre 2026')
        ->toContain('09:00–11:45')
        ->toContain('Prezzo al momento della prenotazione')
        ->toContain("92,00\u{00A0}€")
        ->toContain('Capelli lunghi')
        ->not->toContain('120,00');
});

test('the phone number is never in the email', function () {
    $body = brBody(brMail(brBooking()));

    expect($body)->not->toContain('333 123 4567')
        ->not->toContain('3331234567')
        ->not->toContain('Telefono');
});

test('the subject holds the service name only: no link, no token, no customer name', function () {
    $booking = brBooking(['customer_name' => 'Awa Diop']);

    $subject = (string) brMail($booking)->subject;

    expect($subject)->toBe('Richiesta inviata: Treccine')
        ->not->toContain($booking->public_token)
        ->not->toContain('http')
        ->not->toContain('Awa');
});

test('what the customer wrote is shown as text, never as HTML or Markdown', function () {
    $booking = brBooking([
        'customer_name' => '<b>Awa</b> [link](https://evil.example.test)',
        'notes' => "<script>alert(1)</script>\n**grassetto** [clicca](https://evil.example.test)",
    ]);
    $mail = brMail($booking);

    $html = (string) $mail->render();
    $text = view($mail->view['text'], $mail->viewData)->render();

    expect($html)->not->toContain('<script>')
        ->not->toContain('<b>Awa</b>')
        ->not->toContain('<strong>grassetto</strong>')
        ->not->toContain('href="https://evil.example.test"')
        ->toContain('&lt;script&gt;alert(1)&lt;/script&gt;<br />')
        ->toContain('**grassetto** [clicca](https://evil.example.test)')
        ->toContain('&lt;b&gt;Awa&lt;/b&gt;')
        ->and(substr_count($html, '<a '))->toBe(1)
        // The text version shows the characters as written.
        ->and($text)->toContain("<script>alert(1)</script>\n**grassetto** [clicca](https://evil.example.test)");
});

// ---------------------------------------------------------------------------------------------
// The queue
// ---------------------------------------------------------------------------------------------

test('the email is queued with three attempts, the backoff and deleteWhenMissingModels', function () {
    Queue::fake();

    brPost(brProvider())->assertSessionHasNoErrors();

    Queue::assertPushed(SendQueuedNotifications::class, 2);
    $job = Queue::pushed(SendQueuedNotifications::class, fn (SendQueuedNotifications $job) => $job->notification instanceof BookingReceivedNotification)->sole();
    $notification = $job->notification;
    assert($notification instanceof BookingReceivedNotification);

    expect($job->tries)->toBe(3)
        ->and($notification->backoff())->toBe([60, 300])
        ->and($notification->deleteWhenMissingModels)->toBeTrue()
        ->and($notification->locale)->toBe('it')
        ->and($notification->via(new AnonymousNotifiable))->toBe(['mail'])
        ->and(brSent())->toBeEmpty();
});

test('the queued job holds the booking id, never the public token nor what the customer wrote', function () {
    // The real database queue: the payload is the row written in the jobs table.
    config()->set('queue.default', 'database');

    brPost(brProvider(), ['notes' => 'Capelli lunghi'])->assertSessionHasNoErrors();
    $booking = Booking::query()->sole();

    $payloads = DB::table('jobs')->pluck('payload');
    $received = $payloads->filter(fn (string $payload) => str_contains($payload, 'BookingReceivedNotification'))->sole();

    // The address of the on-demand route is in the job; the token and the other fields are not.
    expect($received)->not->toContain($booking->public_token)
        ->not->toContain('Awa Diop')
        ->not->toContain('333 123 4567')
        ->not->toContain('Capelli lunghi')
        ->toContain('ModelIdentifier')
        ->toContain(BR_EMAIL)
        ->and($payloads)->toHaveCount(2);
});

test('a failure while queueing the email is reported, and the booking stays with its private page', function () {
    Exceptions::fake();
    $this->mock(Dispatcher::class)
        ->shouldReceive('send')
        ->andThrow(new RuntimeException('Coda non disponibile'));

    $response = brPost(brProvider());
    $booking = Booking::query()->sole();

    $response->assertRedirect(route('bookings.show', ['booking' => $booking->public_token]))
        ->assertSessionHasNoErrors();
    // Once for the provider's email, once for the customer's.
    Exceptions::assertReportedCount(2);
    $this->get((string) $response->headers->get('Location'))->assertOk();
});

// ---------------------------------------------------------------------------------------------
// At most three per hour towards one address
// ---------------------------------------------------------------------------------------------

test('the fourth email of the hour to one address is not sent, and only the fact is logged', function () {
    Notification::fake();
    Log::spy();

    foreach (range(1, 4) as $_) {
        BookingReceivedNotification::sendFor(brBooking());
    }

    Notification::assertSentOnDemandTimes(BookingReceivedNotification::class, 3);
    Log::shouldHaveReceived('info')
        ->once()
        ->withArgs(fn (string $message, array $context = []) => $message === 'Booking received email skipped: hourly limit for one address reached.' && $context === []);
    expect(Booking::query()->count())->toBe(4);

    // An hour later the address gets emails again.
    $this->travel(61)->minutes();
    BookingReceivedNotification::sendFor(brBooking());
    Notification::assertSentOnDemandTimes(BookingReceivedNotification::class, 4);
});

test('different addresses do not share the limit, upper and lower case do', function () {
    Notification::fake();

    foreach (range(1, 3) as $_) {
        BookingReceivedNotification::sendFor(brBooking(['customer_email' => BR_EMAIL]));
    }
    BookingReceivedNotification::sendFor(brBooking(['customer_email' => 'fatou@example.test']));
    BookingReceivedNotification::sendFor(brBooking(['customer_email' => 'AWA@Example.TEST']));

    // Three to awa@, one to fatou@; the fifth, to AWA@Example.TEST, is the fourth to the same
    // mailbox and is not sent: five would mean that upper case counts as another address.
    Notification::assertSentOnDemandTimes(BookingReceivedNotification::class, 4);
    Notification::assertSentOnDemand(BookingReceivedNotification::class, fn ($notification, $channels, AnonymousNotifiable $notifiable) => $notifiable->routes === ['mail' => 'fatou@example.test']);
});

test('the limit key is a hash of the address in lower case: the address is never in the cache', function () {
    $key = BookingReceivedNotification::limiterKey('Awa@Example.test ');

    expect($key)->toBe(BookingReceivedNotification::limiterKey(BR_EMAIL))
        ->not->toContain('awa')
        ->not->toContain('example')
        ->toMatch('/^booking-received-email:[0-9a-f]{64}$/')
        ->and($key)->not->toBe('booking-received-email:'.hash('sha256', BR_EMAIL));

    Notification::fake();
    BookingReceivedNotification::sendFor(brBooking());
    expect(Cache::has($key))->toBeTrue();
});

// ---------------------------------------------------------------------------------------------
// The texts
// ---------------------------------------------------------------------------------------------

test('the texts in the three languages', function () {
    expect(__('booking.received.subject', ['service' => 'Treccine'], 'it'))->toBe('Richiesta inviata: Treccine')
        ->and(__('booking.received.intro', ['provider' => 'Salone Awa'], 'it'))->toBe('Richiesta inviata a Salone Awa: ti scriveremo quando risponde.')
        ->and(__('booking.received.warning', [], 'it'))->toBe('Chiunque abbia questo link può vedere e annullare la prenotazione. Non inoltrare questa email.')
        ->and(__('booking.received.subject', ['service' => 'Treccine'], 'fr'))->toBe("Demande envoyée\u{00A0}: Treccine")
        ->and(__('booking.received.intro', ['provider' => 'Salone Awa'], 'fr'))->toBe("Demande envoyée à Salone Awa\u{00A0}: nous vous écrirons dès sa réponse.")
        ->and(__('booking.received.warning', [], 'fr'))->toBe('Toute personne disposant de ce lien peut voir et annuler la réservation. Ne transférez pas cet e-mail.')
        ->and(__('booking.received.subject', ['service' => 'Treccine'], 'en'))->toBe('Request sent: Treccine')
        ->and(__('booking.received.intro', ['provider' => 'Salone Awa'], 'en'))->toBe('Request sent to Salone Awa: we will write to you when they reply.')
        ->and(__('booking.received.warning', [], 'en'))->toBe('Anyone with this link can see and cancel the booking. Do not forward this email.');

    $privacy = ['it' => 'conferma dell\'invio con il link privato', 'fr' => 'confirmation de l\'envoi, avec le lien privé', 'en' => 'confirmation right away that the request was sent, with the booking\'s private link'];
    foreach ($privacy as $locale => $fragment) {
        expect(__('privacy.purpose.customers', [], $locale))->toContain($fragment);
    }
    expect(__('privacy.purpose.customers', [], 'it'))->toContain('le note che hai scritto')
        ->and(__('privacy.purpose.customers', [], 'fr'))->toContain('les remarques que vous avez écrites')
        ->and(__('privacy.purpose.customers', [], 'en'))->toContain('the notes you wrote');
});

test('the whole email in French, with the price written as in French', function () {
    BookingReceivedNotification::sendFor(brBooking(['locale' => 'fr']));
    $email = collect(brSent())->sole();
    $body = $email->getHtmlBody()."\n".$email->getTextBody();

    expect($body)->toContain('Bonjour Awa Diop,')
        ->toContain("Demande envoyée à Salone Awa\u{00A0}: nous vous écrirons dès sa réponse.")
        ->toContain('Prix au moment de la réservation')
        ->toContain('jeudi 29 octobre 2026')
        ->toContain("92,00\u{00A0}€")
        ->toContain('Ne transférez pas cet e-mail.');
});
