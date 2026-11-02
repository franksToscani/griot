<?php

use App\Booking\LocalTime;
use App\Enums\BookingStatus;
use App\Enums\Weekday;
use App\Models\Availability;
use App\Models\Booking;
use App\Models\Category;
use App\Models\Provider;
use App\Models\Service;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\CategorySeeder;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Mail\Mailer;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Process;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\Mime\Email;

// English (issue #54, phase 3b): open to everyone, like Italian and French, with British dates and
// prices ("Thursday 29 October 2026", "€90.00") both on the server and in the browser.

/**
 * @return array<string, mixed>
 */
function engProps(TestResponse $response): array
{
    $props = [];
    $response->assertOk()->assertInertia(function (Assert $page) use (&$props) {
        $props = $page->toArray()['props'];
    });

    return $props;
}

/**
 * The emails really sent, by recipient address.
 *
 * @return array<string, Email>
 */
function engSent(): array
{
    $mailer = Mail::mailer();
    assert($mailer instanceof Mailer);
    $transport = $mailer->getSymfonyTransport();
    assert($transport instanceof ArrayTransport);

    $emails = [];

    foreach ($transport->messages() as $sent) {
        $email = $sent->getOriginalMessage();
        assert($email instanceof Email);
        $emails[$email->getTo()[0]->getAddress()] = $email;
    }

    return $emails;
}

/**
 * A provider working on Mondays 09:00-13:00 local, with a pending booking of "Treccine" on Monday
 * 2 November 2026, 08:00-09:00 UTC (09:00-10:00 in Italy), in the customer's language $locale.
 *
 * @return array{0: Provider, 1: Booking}
 */
function engBooking(string $locale, BookingStatus $status = BookingStatus::Pending): array
{
    test()->travelTo(CarbonImmutable::parse('2026-10-27 08:00', 'UTC'));
    $provider = Provider::factory()->create(['name' => 'Salone Awa', 'is_published' => true]);
    Availability::factory()->for($provider)->create(['weekday' => Weekday::Monday, 'start_time' => '09:00:00', 'end_time' => '13:00:00']);
    $service = Service::factory()->for($provider)->create(['name' => 'Treccine', 'duration_min' => 60, 'duration_max' => 60]);

    $booking = Booking::factory()->for($provider)->for($service)->create([
        'status' => $status,
        'customer_name' => 'Awa Diop',
        'customer_phone' => '+393331234567',
        'customer_email' => 'awa@example.com',
        'starts_at' => CarbonImmutable::parse('2026-11-02 08:00', 'UTC'),
        'ends_at' => CarbonImmutable::parse('2026-11-02 09:00', 'UTC'),
        'locale' => $locale,
    ]);

    return [$provider, $booking];
}

/**
 * Run resources/js/lib/format.ts with Node, as the browser would, and give back what it printed.
 * Node reads TypeScript by stripping the types (no build needed).
 *
 * @return array<string, string>
 */
function engRunFormat(string $calls): array
{
    $script = sys_get_temp_dir().'/griot-format-'.bin2hex(random_bytes(6)).'.mts';
    $format = resource_path('js/lib/format.ts');
    file_put_contents($script, "import * as f from '{$format}';\nconsole.log(JSON.stringify({$calls}));\n");

    try {
        $result = Process::run(['node', '--experimental-strip-types', '--no-warnings', $script]);
    } finally {
        unlink($script);
    }

    expect($result->successful())->toBeTrue($result->errorOutput());

    return json_decode($result->output(), true, flags: JSON_THROW_ON_ERROR);
}

// ---------------------------------------------------------------------------------------------
// Open to everyone
// ---------------------------------------------------------------------------------------------

test('without any variable the selector offers Italian, French and English', function () {
    expect(engProps($this->get(route('providers.index')))['locales'])->toBe([
        ['code' => 'it', 'name' => 'Italiano'],
        ['code' => 'fr', 'name' => 'Français'],
        ['code' => 'en', 'name' => 'English'],
    ]);
});

test('with the cookie en a page is in English, with lang="en"', function () {
    $response = $this->withCookie('locale', 'en')->get(route('providers.index'));
    $props = engProps($response);

    expect($props['locale'])->toBe('en')
        ->and($props['translations']['providers.list.title'])->toBe('Find a provider');
    $response->assertSee('<html lang="en"', false);
});

test('a logged-in user who chooses English keeps it', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->post(route('locale.update'), ['locale' => 'en'])
        ->assertSessionHasNoErrors()
        ->assertCookie('locale', 'en');

    expect($user->fresh()->locale)->toBe('en');
});

test('a validation message of Laravel, and wrong credentials, are in English', function () {
    $this->withCookie('locale', 'en')->from(route('login'))
        ->post(route('login.store'), ['email' => '', 'password' => ''])
        ->assertSessionHasErrors([
            'email' => 'The email field is required.',
            'password' => 'The password field is required.',
        ]);

    $user = User::factory()->create();
    $this->withCookie('locale', 'en')->from(route('login'))
        ->post(route('login.store'), ['email' => $user->email, 'password' => 'not-the-password'])
        ->assertSessionHasErrors(['email' => 'The email or password is incorrect.']);
});

test('the error pages are in English', function () {
    $this->withCookie('locale', 'en')->get('/address-that-does-not-exist')
        ->assertNotFound()
        ->assertSee('<html lang="en">', false)
        ->assertSee('Page not found');

    app()->instance(PreventRequestForgery::class, new class(app(), app('encrypter')) extends PreventRequestForgery
    {
        protected function runningUnitTests()
        {
            return false;
        }
    });

    $this->withCookie('locale', 'en')->post(route('locale.update'), ['locale' => 'it'])
        ->assertStatus(419)
        ->assertSee('<html lang="en">', false)
        ->assertSee('Page expired');
});

// ---------------------------------------------------------------------------------------------
// The customer in English
// ---------------------------------------------------------------------------------------------

test('a customer who booked in English gets the answer in English, from a provider in Italian', function () {
    [$provider, $booking] = engBooking('en');

    $this->actingAs($provider->user)->post(route('provider.bookings.confirm', $booking->id))->assertSessionHasNoErrors();

    $email = engSent()['awa@example.com'];
    expect($email->getSubject())->toBe('Booking confirmed: Treccine')
        ->and($email->getTextBody())->toContain('Hello Awa Diop,')
        ->toContain('Monday 2 November 2026, 09:00–10:00')
        ->and($email->getHtmlBody())->toContain('<html lang="en">');
});

test('the WhatsApp text of a customer in English is in English, with the date in English', function () {
    [$provider] = engBooking('en', BookingStatus::Confirmed);

    $props = engProps($this->actingAs($provider->user)->get(route('provider.bookings.index')));
    $text = rawurldecode((string) parse_url($props['bookings']['data'][0]['whatsapp_url'], PHP_URL_QUERY));

    expect($text)->toStartWith('text=Hello Awa Diop, Salone Awa has confirmed your booking for Treccine on Monday 2 November 2026, 09:00–10:00.')
        ->toContain('Details: http')
        ->and($props['locale'])->toBe('it');
});

test('a booking sent with the cookie en keeps English as the customer\'s language', function () {
    [$provider] = engBooking('it');
    $service = $provider->services()->sole();

    $this->withCookie('locale', 'en')
        ->post(route('providers.bookings.store', $provider->slug), [
            'service' => $service->id,
            'starts_at' => '2026-11-02T10:00:00Z',
            'customer_name' => 'John Smith',
            'customer_phone' => '+447700900123',
        ])
        ->assertSessionHasNoErrors();

    expect(Booking::query()->where('customer_name', 'John Smith')->sole()->locale)->toBe('en');
});

test('the categories have their English names', function () {
    $this->seed(CategorySeeder::class);
    app()->setLocale('en');

    expect(Category::query()->orderBy('slug')->get()->mapWithKeys(fn (Category $category) => [$category->slug => $category->displayName()])->all())->toBe([
        'barbiere' => 'Barber',
        'ciglia' => 'Lashes',
        'couture' => 'Couture',
        'nails' => 'Nails',
        'ristorazione' => 'Food',
        'sopracciglia' => 'Brows',
        'treccine' => 'Braids',
    ]);
});

// ---------------------------------------------------------------------------------------------
// Dates and prices: the server and the browser write the same
// ---------------------------------------------------------------------------------------------

test('the long date is the same on the server and in the browser, in every language', function () {
    $js = engRunFormat("{ it: f.formatLongDate('it', '2026-10-29'), fr: f.formatLongDate('fr', '2026-10-29'), en: f.formatLongDate('en', '2026-10-29') }");
    $instant = CarbonImmutable::parse('2026-10-29 08:00', 'UTC');

    expect($js)->toBe([
        'it' => 'giovedì 29 ottobre 2026',
        'fr' => 'jeudi 29 octobre 2026',
        'en' => 'Thursday 29 October 2026',
    ]);

    foreach ($js as $locale => $date) {
        expect(LocalTime::longDate($instant, $locale))->toBe($date);
    }
});

test('the browser writes British prices in English, and the other languages as before', function () {
    expect(engRunFormat("{ it: f.formatPrice('it', '90.00'), fr: f.formatPrice('fr', '7.50'), en: f.formatPrice('en', '90.00'), map: f.intlLocale('en'), itMap: f.intlLocale('it') }"))->toBe([
        'it' => "90,00\u{00A0}€",
        'fr' => "7,50\u{00A0}€",
        'en' => '€90.00',
        'map' => 'en-GB',
        'itMap' => 'it',
    ]);
});

test('Intl is used in lib/format.ts only, through intlLocale', function () {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(resource_path('js'), FilesystemIterator::SKIP_DOTS));

    foreach ($iterator as $file) {
        $path = $file->getPathname();

        if (preg_match('#/resources/js/(routes|actions|wayfinder)/#', $path) === 1 || preg_match('/\.tsx?$/', $path) !== 1 || str_ends_with($path, 'lib/format.ts')) {
            continue;
        }

        expect(str_contains((string) file_get_contents($path), 'Intl.'))->toBeFalse("Intl used outside lib/format.ts in {$path}");
    }

    $format = (string) file_get_contents(resource_path('js/lib/format.ts'));
    expect(substr_count($format, 'new Intl.'))->toBe(substr_count($format, 'new Intl.DateTimeFormat(intlLocale(locale)') + substr_count($format, 'new Intl.NumberFormat(intlLocale(locale)'));
});

// ---------------------------------------------------------------------------------------------
// Texts
// ---------------------------------------------------------------------------------------------

test('the English texts of the glossary are exactly the expected ones', function (string $key, string $text) {
    expect(__($key, [], 'en'))->toBe($text);
})->with([
    ['booking.form.open', 'Book'],
    ['booking.form.submit', 'Send booking request'],
    ['booking.notification.subject', 'New booking request: :service'],
    ['booking.confirmation.status_pending', 'Waiting for confirmation'],
    ['provider.bookings.status.pending', 'Pending'],
    ['provider.bookings.status.confirmed', 'Confirmed'],
    ['provider.bookings.status.cancelled', 'Cancelled'],
    ['booking.notice.subject_rejected', 'Booking request not accepted: :service'],
    ['provider.bookings.actions.reject', 'Decline'],
    ['provider.services.title', 'Your services'],
    ['provider.options.title', 'Options and extras'],
    ['provider.availability.title', 'Your working hours'],
    ['provider.area.availability', 'Your working hours'],
    ['provider.availability.add_title', 'Add working hours'],
    ['provider.availability.form.submit', 'Add working hours'],
    ['provider.availability.validation.limit_reached', 'A day can have up to :max time windows.'],
    ['provider.availability.validation.overlap', 'This time window overlaps another one on the same day.'],
    ['provider.availability.help_timezone', 'Times are in Italian time (Europe/Rome).'],
    ['provider.bookings.whatsapp_text_rejected', 'Hello :name, :provider is unable to accept your booking request for :service on :when.'],
    ['provider.bookings.whatsapp_button', 'Message on WhatsApp'],
    ['provider.setup.form.bio', 'About your business (optional)'],
    ['provider.validation.bio_max', 'This text can be up to :max characters.'],
    ['account.forgot.return', 'Or go back to the'],
    ['account.forgot.return_link', 'login page'],
    ['settings.appearance.system', 'System'],
    ['auth.failed', 'The email or password is incorrect.'],
    ['booking.form.times', 'Available time slots'],
    ['providers.list.title', 'Find a provider'],
    ['booking.notification.customer', 'Customer'],
    ['provider.edit.title', 'Your profile'],
    ['provider.publish.submit', 'Publish profile'],
    ['provider.publish.unpublish', 'Unpublish'],
    ['booking.reminder.subject', 'Pending booking request: :service'],
    ['booking.confirmation.link_title', 'Your private link'],
    ['booking.notification.email', 'Email'],
    ['nav.dashboard', 'My dashboard'],
    ['provider.area.title', 'Your area'],
    ['nav.login', 'Log in'],
    ['nav.logout', 'Log out'],
    ['nav.register', 'Sign up'],
]);

test('the English texts use the same terms for the same things', function () {
    /** @var array<string, string> $english */
    $english = json_decode((string) file_get_contents(lang_path('en.json')), true, flags: JSON_THROW_ON_ERROR);
    $availability = array_filter($english, fn (string $key) => str_starts_with($key, 'provider.availability.') || $key === 'provider.area.availability' || $key === 'provider.publish.missing_availability', ARRAY_FILTER_USE_KEY);

    // The weekly hours: "working hours" for the whole, "time window" for one of them.
    foreach ($availability as $key => $text) {
        expect(preg_match('/sets? of hours|these hours|\bAdd hours\b|\bYour hours\b|^Hours (added|updated|deleted)/i', $text))->toBe(0, "Old wording in {$key}: {$text}");
    }

    // A request for a booking is a "booking request", never a bare "request".
    foreach (['booking.create.created', 'booking.form.submit', 'booking.confirmation.pending_note', 'booking.notification.subject', 'booking.notification.intro', 'booking.reminder.subject', 'booking.reminder.intro', 'booking.reminder.action', 'booking.notice.subject_rejected', 'booking.notice.rejected', 'provider.bookings.help', 'provider.bookings.rejected', 'provider.bookings.actions.reject_title', 'provider.bookings.whatsapp_text_rejected'] as $key) {
        expect(stripos($english[$key], 'booking request'))->not->toBeFalse("No \"booking request\" in {$key}: {$english[$key]}");
    }
});
