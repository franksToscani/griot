<?php

use App\Booking\BookingOutcome;
use App\Enums\BookingStatus;
use App\Enums\Weekday;
use App\Models\Availability;
use App\Models\Booking;
use App\Models\Provider;
use App\Models\Service;
use App\Models\User;
use App\Notifications\BookingStatusNotification;
use App\Support\Locales;
use Carbon\CarbonImmutable;
use Illuminate\Mail\Mailer;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\Mime\Email;

// Each email and each WhatsApp text in the language of who reads it (issue #54, phase 3a-2).
// "Now" is Tuesday 27 October 2026, 08:00 UTC; the provider works on Mondays 09:00-13:00 local,
// which on Monday 2 November (winter time) is 08:00-12:00 UTC. French is open as with
// APP_PREVIEW_LOCALES=fr, unless a test says otherwise.
beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-27 08:00', 'UTC'));
    config()->set('app.available_locales', ['it' => 'Italiano', 'fr' => 'Français']);
    config()->set('booking.reminder_after_minutes', 720);
});

function rclProvider(?string $userLocale = null): Provider
{
    $user = User::factory()->create(['email' => 'salone@example.com']);
    // Not fillable: assigned as LocaleController does.
    $user->locale = $userLocale;
    $user->save();

    $provider = Provider::factory()->for($user)->create(['name' => 'Salone Awa', 'is_published' => true]);
    Availability::factory()->for($provider)->create([
        'weekday' => Weekday::Monday,
        'start_time' => '09:00:00',
        'end_time' => '13:00:00',
    ]);

    return $provider;
}

function rclService(Provider $provider): Service
{
    return Service::factory()->for($provider)->create(['name' => 'Treccine', 'duration_min' => 60, 'duration_max' => 60]);
}

/**
 * A booking of the provider on Monday 2 November, 08:00-09:00 UTC (09:00-10:00 in Italy).
 *
 * @param  array<string, mixed>  $override
 */
function rclBooking(Provider $provider, string $locale, array $override = []): Booking
{
    return Booking::factory()->for($provider)->for(rclService($provider))->create([
        'status' => BookingStatus::Pending,
        'customer_name' => 'Awa Diop',
        'customer_phone' => '+393331234567',
        'customer_email' => 'awa@example.com',
        'starts_at' => CarbonImmutable::parse('2026-11-02 08:00', 'UTC'),
        'ends_at' => CarbonImmutable::parse('2026-11-02 09:00', 'UTC'),
        'locale' => $locale,
        ...$override,
    ]);
}

function rclTransport(): ArrayTransport
{
    $mailer = Mail::mailer();
    assert($mailer instanceof Mailer);
    $transport = $mailer->getSymfonyTransport();
    assert($transport instanceof ArrayTransport);

    return $transport;
}

/**
 * The emails really sent, by recipient address.
 *
 * @return array<string, Email>
 */
function rclSent(): array
{
    $emails = [];

    foreach (rclTransport()->messages() as $sent) {
        $email = $sent->getOriginalMessage();
        assert($email instanceof Email);
        $emails[$email->getTo()[0]->getAddress()] = $email;
    }

    return $emails;
}

/**
 * @return array<string, mixed>
 */
function rclProps(TestResponse $response): array
{
    $props = [];
    $response->assertOk()->assertInertia(function (Assert $page) use (&$props) {
        $props = $page->toArray()['props'];
    });

    return $props;
}

// ---------------------------------------------------------------------------------------------
// 1. The email to the customer
// ---------------------------------------------------------------------------------------------

test('the customer gets the answer in French, though the provider uses the site in Italian', function () {
    $provider = rclProvider('it');
    $booking = rclBooking($provider, 'fr');

    $this->actingAs($provider->user)->post(route('provider.bookings.confirm', $booking->id))
        ->assertSessionHasNoErrors()
        // The provider's page stays in Italian.
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Prenotazione confermata.']);

    $email = rclSent()['awa@example.com'];
    expect($email->getSubject())->toBe("Réservation confirmée\u{00A0}: Treccine")
        ->and($email->getTextBody())->toContain('Bonjour Awa Diop,')
        ->toContain('lundi 2 novembre 2026, 09:00–10:00')
        ->and($email->getHtmlBody())->toContain('<html lang="fr">');
    expect(app()->getLocale())->toBe('it');
});

test('a customer who booked in Italian gets the answer in Italian, from a provider in French', function () {
    $provider = rclProvider('fr');
    $booking = rclBooking($provider, 'it');

    $this->actingAs($provider->user)->post(route('provider.bookings.reject', $booking->id))->assertSessionHasNoErrors();

    expect(rclSent()['awa@example.com']->getSubject())->toBe('Richiesta non accettata: Treccine');
});

// ---------------------------------------------------------------------------------------------
// 2. The emails to the provider
// ---------------------------------------------------------------------------------------------

test('a request sent in French reaches the provider in the provider\'s language', function (?string $userLocale, string $subject) {
    $provider = rclProvider($userLocale);
    $service = rclService($provider);

    $this->withCookie('locale', 'fr')
        ->post(route('providers.bookings.store', $provider->slug), [
            'service' => $service->id,
            'starts_at' => '2026-11-02T09:00:00Z',
            'customer_name' => 'Awa Diop',
            'customer_phone' => '+33612345678',
        ])
        ->assertSessionHasNoErrors();

    expect(rclSent()['salone@example.com']->getSubject())->toBe($subject);
    // The customer's language is kept for later.
    expect(Booking::query()->sole()->locale)->toBe('fr');
})->with([
    'provider in Italian' => ['it', 'Nuova richiesta di prenotazione: Treccine'],
    'provider who never chose' => [null, 'Nuova richiesta di prenotazione: Treccine'],
    'provider in French' => ['fr', "Nouvelle demande de réservation\u{00A0}: Treccine"],
]);

test('a cancellation by a customer in French reaches the provider in the provider\'s language', function () {
    config()->set('booking.cancellation_notice_minutes', 1440);
    $provider = rclProvider('it');
    $booking = rclBooking($provider, 'fr', ['status' => BookingStatus::Confirmed]);

    $this->withCookie('locale', 'fr')
        ->post(route('bookings.cancel', ['booking' => $booking->public_token]))
        ->assertSessionHasNoErrors();

    expect(rclSent()['salone@example.com']->getSubject())->toBe('Prenotazione annullata dal cliente: Treccine');
});

test('the reminder of the command reaches each provider in the provider\'s language', function () {
    $italian = rclProvider('it');
    $created = CarbonImmutable::now()->subMinutes(720);
    rclBooking($italian, 'fr', ['created_at' => $created]);
    $french = Provider::factory()->for(tap(User::factory()->create(['email' => 'barbier@example.com']), function (User $user) {
        $user->locale = 'fr';
        $user->save();
    }))->create(['is_published' => true]);
    rclBooking($french, 'it', ['created_at' => $created]);

    $this->artisan('bookings:remind-pending')->assertSuccessful();

    expect(rclSent()['salone@example.com']->getSubject())->toBe('Richiesta in attesa: Treccine')
        ->and(rclSent()['barbier@example.com']->getSubject())->toBe("Demande en attente\u{00A0}: Treccine");
    // The command does not change the language of the application.
    expect(app()->getLocale())->toBe(config('app.locale'));
});

// ---------------------------------------------------------------------------------------------
// 3. The WhatsApp text
// ---------------------------------------------------------------------------------------------

test('the WhatsApp text is in the customer\'s language, the provider\'s page stays in Italian', function (string $locale, string $text) {
    $provider = rclProvider('it');
    rclBooking($provider, $locale, ['status' => BookingStatus::Confirmed]);

    $props = rclProps($this->actingAs($provider->user)->get(route('provider.bookings.index')));
    $url = $props['bookings']['data'][0]['whatsapp_url'];

    expect(rawurldecode((string) parse_url($url, PHP_URL_QUERY)))->toStartWith('text='.$text)
        ->and($props['locale'])->toBe('it')
        ->and($props['translations']['provider.bookings.title'])->toBe('Le tue prenotazioni');
    // The language itself is not sent to the page.
    expect($props['bookings']['data'][0])->not->toHaveKey('locale');
})->with([
    'French' => ['fr', 'Bonjour Awa Diop, Salone Awa a confirmé votre réservation de Treccine pour le lundi 2 novembre 2026, 09:00–10:00.'],
    'Italian' => ['it', 'Ciao Awa Diop, Salone Awa ha confermato la tua prenotazione di Treccine per lunedì 2 novembre 2026, 09:00–10:00.'],
]);

test('the reason and the link of the WhatsApp text are in the customer\'s language too', function () {
    $provider = rclProvider('it');
    rclBooking($provider, 'fr', ['status' => BookingStatus::Cancelled, 'cancelled_by' => 'provider_cancel', 'cancellation_reason' => 'Malattia']);

    $url = rclProps($this->actingAs($provider->user)->get(route('provider.bookings.index', ['status' => 'cancelled'])))['bookings']['data'][0]['whatsapp_url'];
    $text = rawurldecode((string) parse_url($url, PHP_URL_QUERY));

    expect($text)->toContain('a annulé votre réservation')
        ->toContain("Motif\u{00A0}: Malattia")
        ->toContain("Détails\u{00A0}: http");
});

// ---------------------------------------------------------------------------------------------
// 4. A booking written by the provider
// ---------------------------------------------------------------------------------------------

test('the provider chooses the customer\'s language, by default the provider\'s own', function (?string $cookie, array $field, string $saved) {
    $provider = rclProvider('it');
    $service = rclService($provider);
    $test = $cookie === null ? $this : $this->withCookie('locale', $cookie);

    $test->actingAs($provider->user)
        ->post(route('provider.bookings.store'), [
            'service' => $service->id,
            'starts_at' => '2026-11-02T09:00:00Z',
            'customer_name' => 'Awa Diop',
            'customer_phone' => '+33612345678',
            ...$field,
        ])
        ->assertSessionHasNoErrors();

    expect(Booking::query()->sole()->locale)->toBe($saved);
})->with([
    'chosen French' => [null, ['customer_locale' => 'fr'], 'fr'],
    'chosen Italian on a French page' => ['fr', ['customer_locale' => 'it'], 'it'],
    'not chosen, page in Italian' => [null, [], 'it'],
    'not chosen, page in French' => ['fr', [], 'fr'],
    'empty' => ['fr', ['customer_locale' => ''], 'fr'],
]);

test('a language that is not available is refused in the provider\'s form', function (array $available, string $value) {
    config()->set('app.available_locales', $available);
    $provider = rclProvider('it');
    $service = rclService($provider);

    $this->actingAs($provider->user)
        ->post(route('provider.bookings.store'), [
            'service' => $service->id,
            'starts_at' => '2026-11-02T09:00:00Z',
            'customer_name' => 'Awa Diop',
            'customer_phone' => '+33612345678',
            'customer_locale' => $value,
        ])
        ->assertSessionHasErrors(['customer_locale' => 'Scegli una lingua tra quelle disponibili.']);

    expect(Booking::query()->count())->toBe(0);
})->with([
    'unknown' => [['it' => 'Italiano', 'fr' => 'Français'], 'de'],
    'French not open' => [['it' => 'Italiano'], 'fr'],
]);

test('the field of the customer\'s language is in the form, hidden with one language', function () {
    $source = (string) file_get_contents(resource_path('js/pages/provider/bookings/create.tsx'));

    expect($source)
        ->toMatch('/function CustomerLocaleField\(\{ error \}: \{ error\?: string \}\) \{.*?if \(locales\.length <= 1\) \{\s*return null;\s*\}/s')
        ->toMatch('/<select\s+id="customer_locale"\s+name="customer_locale"\s+defaultValue=\{locale\}/')
        ->toContain('<CustomerLocaleField error={errors.customer_locale} />')
        ->toContain("{t('provider.bookings.create.customer_locale')}");
});

// ---------------------------------------------------------------------------------------------
// 5. The public form cannot choose the language
// ---------------------------------------------------------------------------------------------

test('the public form takes the language of the page, never a field of the body', function () {
    $provider = rclProvider('it');
    $service = rclService($provider);

    $this->post(route('providers.bookings.store', $provider->slug), [
        'service' => $service->id,
        'starts_at' => '2026-11-02T09:00:00Z',
        'customer_name' => 'Awa Diop',
        'customer_phone' => '+33612345678',
        'locale' => 'fr',
        'customer_locale' => 'fr',
    ])->assertSessionHasNoErrors();

    expect(Booking::query()->sole()->locale)->toBe('it');
});

test('the language is not fillable, on bookings and on users', function () {
    $booking = new Booking(['locale' => 'fr']);
    $user = new User(['name' => 'Awa', 'email' => 'awa@example.com', 'password' => 'x', 'locale' => 'fr']);

    expect($booking->isFillable('locale'))->toBeFalse()
        ->and($booking->locale)->toBe('it')
        ->and($user->isFillable('locale'))->toBeFalse()
        ->and($user->locale)->toBeNull();
});

// ---------------------------------------------------------------------------------------------
// 6. Locales
// ---------------------------------------------------------------------------------------------

test('a language is written for delivery only if its file exists, otherwise Italian', function (?string $locale, string $delivery) {
    expect(Locales::forDelivery($locale))->toBe($delivery);
})->with([
    'French' => ['fr', 'fr'],
    'Italian' => ['it', 'it'],
    'never chosen' => [null, 'it'],
    'not written' => ['de', 'it'],
    'not a language' => ['../it', 'it'],
    'uppercase' => ['FR', 'it'],
]);

test('a language is available only if the config opens it', function () {
    expect(Locales::available('fr'))->toBe('fr');

    config()->set('app.available_locales', ['it' => 'Italiano']);
    expect(Locales::available('fr'))->toBeNull()
        ->and(Locales::available('it'))->toBe('it')
        ->and(Locales::available(null))->toBeNull()
        // Still written: an email to a customer who booked in French during a preview stays French.
        ->and(Locales::forDelivery('fr'))->toBe('fr');
});

test('a saved language that is not written gives Italian emails', function () {
    $provider = rclProvider('de');
    $booking = rclBooking($provider, 'de');

    $this->actingAs($provider->user)->post(route('provider.bookings.confirm', $booking->id))->assertSessionHasNoErrors();

    expect(rclSent()['awa@example.com']->getSubject())->toBe('Prenotazione confermata: Treccine')
        ->and($provider->user->preferredLocale())->toBe('it');
});

// ---------------------------------------------------------------------------------------------
// 7. The selector remembers the choice of a logged-in user
// ---------------------------------------------------------------------------------------------

test('a logged-in user keeps the chosen language; a guest changes no user', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->post(route('locale.update'), ['locale' => 'fr'])->assertSessionHasNoErrors();
    expect($user->fresh()->locale)->toBe('fr');

    auth()->logout();
    $this->post(route('locale.update'), ['locale' => 'it'])->assertSessionHasNoErrors();
    expect($user->fresh()->locale)->toBe('fr')
        ->and(User::query()->whereNotNull('locale')->count())->toBe(1);

    $this->actingAs($user)->post(route('locale.update'), ['locale' => 'de'])->assertSessionHasErrors('locale');
    expect($user->fresh()->locale)->toBe('fr');
});

// ---------------------------------------------------------------------------------------------
// 8. The order: cookie, then users.locale, then Italian; the private page
// ---------------------------------------------------------------------------------------------

test('the language of a page: the cookie, then the user\'s choice, then Italian', function (?string $cookie, ?string $userLocale, array $available, string $page) {
    config()->set('app.available_locales', $available);
    $user = User::factory()->create();
    $user->locale = $userLocale;
    $user->save();
    $test = $cookie === null ? $this : $this->withCookie('locale', $cookie);

    expect(rclProps($test->actingAs($user)->get(route('provider.setup')))['locale'])->toBe($page);
})->with([
    'the user\'s choice, without a cookie' => [null, 'fr', ['it' => 'Italiano', 'fr' => 'Français'], 'fr'],
    'the cookie wins' => ['it', 'fr', ['it' => 'Italiano', 'fr' => 'Français'], 'it'],
    'never chosen' => [null, null, ['it' => 'Italiano', 'fr' => 'Français'], 'it'],
    'the user\'s choice no longer available' => [null, 'fr', ['it' => 'Italiano'], 'it'],
    'a value not written' => [null, 'de', ['it' => 'Italiano', 'fr' => 'Français'], 'it'],
]);

test('a guest gets the cookie or Italian, never another user\'s choice', function () {
    $user = User::factory()->create();
    $user->locale = 'fr';
    $user->save();

    expect(rclProps($this->get(route('providers.index')))['locale'])->toBe('it')
        ->and(rclProps($this->withCookie('locale', 'fr')->get(route('providers.index')))['locale'])->toBe('fr');
});

test('the private page is in the booking\'s language without a cookie, and the cookie wins', function (?string $cookie, array $available, string $page) {
    config()->set('app.available_locales', $available);
    $booking = rclBooking(rclProvider('it'), 'fr');
    $test = $cookie === null ? $this : $this->withCookie('locale', $cookie);

    $response = $test->get(route('bookings.show', ['booking' => $booking->public_token]));
    $props = rclProps($response);

    expect($props['locale'])->toBe($page)
        ->and($props['translations']['booking.confirmation.title'])->toBe($page === 'fr' ? 'Votre réservation' : 'La tua prenotazione');
    $response->assertSee("<html lang=\"{$page}\"", false);
})->with([
    'without a cookie' => [null, ['it' => 'Italiano', 'fr' => 'Français'], 'fr'],
    'with a cookie' => ['it', ['it' => 'Italiano', 'fr' => 'Français'], 'it'],
    'French not open' => [null, ['it' => 'Italiano'], 'it'],
]);

test('the cancellation from the private page answers in the booking\'s language without a cookie', function () {
    config()->set('booking.cancellation_notice_minutes', 1440);
    $booking = rclBooking(rclProvider('it'), 'fr', ['status' => BookingStatus::Confirmed]);

    $this->post(route('bookings.cancel', ['booking' => $booking->public_token]))
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Réservation annulée.']);
});

// ---------------------------------------------------------------------------------------------
// 9. Emails already queued
// ---------------------------------------------------------------------------------------------

test('an email queued before the language existed is sent in Italian; a new one in the customer\'s', function () {
    config(['queue.default' => 'database']);
    $booking = rclBooking(rclProvider('it'), 'fr', ['status' => BookingStatus::Confirmed]);

    // As the code before this phase queued it: no language in the job.
    Notification::route('mail', 'vecchia@example.com')->notify(new BookingStatusNotification($booking, BookingOutcome::Confirmed));
    BookingStatusNotification::sendFor($booking, BookingOutcome::Confirmed);
    expect(DB::table('jobs')->count())->toBe(2);

    $this->artisan('queue:work', ['--stop-when-empty' => true])->assertSuccessful();

    expect(rclSent()['vecchia@example.com']->getSubject())->toBe('Prenotazione confermata: Treccine')
        ->and(rclSent()['awa@example.com']->getSubject())->toBe("Réservation confirmée\u{00A0}: Treccine")
        ->and(DB::table('failed_jobs')->count())->toBe(0);
});

// ---------------------------------------------------------------------------------------------
// Texts
// ---------------------------------------------------------------------------------------------

test('the texts of the customer\'s language are exactly the expected ones', function () {
    expect(__('provider.bookings.create.customer_locale', [], 'it'))->toBe('Lingua del cliente')
        ->and(__('provider.bookings.create.customer_locale_help', [], 'it'))->toBe('La lingua del messaggio WhatsApp e delle email al cliente.')
        ->and(__('provider.bookings.validation.customer_locale_invalid', [], 'it'))->toBe('Scegli una lingua tra quelle disponibili.')
        ->and(__('provider.bookings.create.customer_locale', [], 'fr'))->toBe('Langue du client')
        ->and(__('provider.bookings.create.customer_locale_help', [], 'fr'))->toBe('La langue du message WhatsApp et des e-mails au client.')
        ->and(__('provider.bookings.validation.customer_locale_invalid', [], 'fr'))->toBe('Choisissez une langue parmi celles disponibles.');
});
