<?php

use App\Booking\AnonymizeOldBookings;
use App\Booking\BookingDuration;
use App\Booking\FreeSlots;
use App\Booking\TimeWindow;
use App\Enums\BookingStatus;
use App\Enums\Weekday;
use App\Http\Requests\StoreBookingRequest;
use App\Http\Requests\StoreProviderBookingRequest;
use App\Models\Availability;
use App\Models\Booking;
use App\Models\Category;
use App\Models\Provider;
use App\Models\Service;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;

// The small polishes of Sprint 10 (#207, #74, #112): the customer's phone as a tel: link, the
// seeder with BookingDuration, the guard of FreeSlots, the name on one line, the limit of the
// profile, the shared form classes, the WhatsApp text of a booking the provider wrote, the
// categories in the order of the page's language, and the title of the settings.

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-16 08:00', 'UTC'));
    app()->setLocale('it');
});

function stpSource(string $path): string
{
    return (string) file_get_contents(base_path($path));
}

/**
 * A booking of a published provider, with the given phone and attributes.
 *
 * @param  array<string, mixed>  $attributes
 */
function stpBooking(?string $phone = '+393331234567', array $attributes = []): Booking
{
    $provider = Provider::factory()->published()->create(['name' => 'Salone Awa']);

    return Booking::factory()->for($provider)->for(Service::factory()->for($provider)->create(['name' => 'Treccine']))->create([
        'starts_at' => CarbonImmutable::parse('2026-11-20 09:00', 'UTC'),
        'ends_at' => CarbonImmutable::parse('2026-11-20 10:00', 'UTC'),
        'customer_name' => 'Awa Diop',
        'customer_phone' => $phone,
        ...$attributes,
    ]);
}

// ---------------------------------------------------------------------------------------------
// #207: the customer's phone, a tel: link
// ---------------------------------------------------------------------------------------------

test('customer_phone_url: tel: and the number in international format, also from a number written by hand', function (string $phone) {
    $booking = stpBooking($phone);

    $this->actingAs($booking->provider->user)
        ->get(route('provider.bookings.show', $booking->id))
        ->assertInertia(fn (Assert $page) => $page->where('booking.customer_phone_url', 'tel:+393331234567'));
})->with(['+393331234567', '+39 333 123 4567', '333 123 4567', '0039 333-123-4567']);

test('customer_phone_url is null for a number that cannot be put in international format', function () {
    // A fixed Italian number written without the prefix: never guessed.
    $booking = stpBooking('06 1234 5678');

    $this->actingAs($booking->provider->user)
        ->get(route('provider.bookings.show', $booking->id))
        ->assertInertia(fn (Assert $page) => $page
            ->where('booking.customer_phone_url', null)
            ->where('booking.customer_phone_display', '06 1234 5678'));
});

test('customer_phone_url is null for an anonymous booking', function () {
    $booking = stpBooking('+393331234567', [
        'starts_at' => CarbonImmutable::parse('2026-01-10 09:00', 'UTC'),
        'ends_at' => CarbonImmutable::parse('2026-01-10 10:00', 'UTC'),
    ]);
    app(AnonymizeOldBookings::class)->run(CarbonImmutable::now());

    $this->actingAs($booking->provider->user)
        ->get(route('provider.bookings.show', $booking->id))
        ->assertInertia(fn (Assert $page) => $page->where('booking.customer_phone_url', null));
});

test('the card: a link with a 44 px target only when there is an address, "Telefono:" outside it', function () {
    $card = stpSource('resources/js/components/provider-booking-card.tsx');

    expect($card)->toMatch("/\\{t\\('provider\\.bookings\\.phone'\\)\\}:\\{' '\\}[\\s\\S]*?\\{booking\\.customer_phone_url !== null \\? \\(\\s*<a\\s+href=\\{booking\\.customer_phone_url\\}\\s+className=\\{cn\\(\\s*'underline underline-offset-4',\\s*touchTarget,\\s*\\)\\}\\s*>\\s*\\{booking\\.customer_phone_display\\}\\s*<\\/a>\\s*\\) : \\(\\s*booking\\.customer_phone_display\\s*\\)\\}/")
        ->not->toContain('aria-label');
});

test('the emails to the provider stay text: no tel: link', function () {
    foreach (['new-booking', 'pending-reminder', 'customer-cancelled'] as $view) {
        expect(stpSource("resources/views/mail/{$view}.blade.php"))->not->toContain('href="tel:');
    }
});

// ---------------------------------------------------------------------------------------------
// #74
// ---------------------------------------------------------------------------------------------

test('the seeder gives every booking the duration of BookingDuration', function () {
    $this->seed(DatabaseSeeder::class);
    $durations = app(BookingDuration::class);

    $bookings = Booking::query()->with(['service', 'options'])->get();
    expect($bookings)->not->toBeEmpty();

    foreach ($bookings as $booking) {
        $minutes = $durations->minutesFor($booking->service, $booking->options->modelKeys());
        expect((int) $booking->starts_at->diffInMinutes($booking->ends_at))->toBe($minutes);
    }

    expect(stpSource('database/seeders/BookingSeeder.php'))->toContain('app(BookingDuration::class)->minutesFor($service, array_values($options->map(fn (ServiceOption $option): int => $option->id)->all()))');
});

test('a slot step of 0 gives no slots, never a loop without end', function () {
    // Without the guard the loop never ends: the time limit stops it, and the test fails.
    set_time_limit(10);
    config()->set('booking.slot_step_minutes', 0);

    try {
        $method = new ReflectionMethod(FreeSlots::class, 'slotsInside');
        $window = new TimeWindow(CarbonImmutable::parse('2026-11-17 08:00', 'UTC'), CarbonImmutable::parse('2026-11-17 12:00', 'UTC'));

        expect($method->invoke(app(FreeSlots::class), $window, 60))->toBe([])
            ->and($method->invoke(app(FreeSlots::class), $window, 0))->toBe([]);
    } finally {
        set_time_limit(0);
    }
});

test('the confirmation page and busyTimes read the dates of the model', function () {
    expect(stpSource('app/Http/Resources/BookingConfirmationResource.php'))
        ->toContain('$start = LocalTime::fromUtc($this->starts_at);')
        ->not->toContain('setTimezone(');
    expect(stpSource('app/Booking/FreeSlots.php'))
        ->toContain('new TimeWindow($booking->starts_at->utc(), $booking->ends_at->utc())')
        ->not->toContain('CarbonImmutable::parse($booking->');
});

test('the customer\'s name is on one line in both forms: line breaks and runs of spaces become one space', function (string $class) {
    $request = $class::create('/', 'POST', [
        'customer_name' => "  Awa\nDiop\r\n\tTelefono:   123  ",
        'customer_phone' => '+393331234567',
    ]);

    expect($request->customer()->name)->toBe('Awa Diop Telefono: 123');
})->with([StoreBookingRequest::class, StoreProviderBookingRequest::class]);

test('a name with a line break written on the public form is saved on one line', function () {
    $provider = Provider::factory()->published()->create();
    $service = Service::factory()->for($provider)->create(['duration_min' => 30, 'duration_max' => 60]);
    Availability::factory()->for($provider)->create(['weekday' => Weekday::Tuesday, 'start_time' => '09:00:00', 'end_time' => '13:00:00']);

    $this->post(route('providers.bookings.store', $provider->slug), [
        'service' => $service->id,
        'starts_at' => '2026-11-17T09:00:00Z',
        'customer_name' => "Awa\nTelefono: 000",
        'customer_phone' => '333 123 4567',
    ])->assertSessionHasNoErrors();

    expect(Booking::query()->sole()->customer_name)->toBe('Awa Telefono: 000');
});

test('the profile forms have a limit per user, with the error on their own page', function (string $method, string $route, string $page) {
    config()->set('booking.rate_limit_services_per_minute', 1);
    $user = User::factory()->create();
    if ($route === 'provider.update') {
        Provider::factory()->for($user)->create();
    }
    $data = ['name' => 'Salone Awa', 'city' => 'Bologna', 'slug' => 'salone-awa'];

    $this->actingAs($user)->{$method}(route($route), $data);
    $this->actingAs($user)->{$method}(route($route), $data)
        ->assertRedirect(route($page))
        ->assertSessionHasErrors(['profile' => __('booking.throttle.too_many')]);

    expect(Route::getRoutes()->getByName($route)?->gatherMiddleware())->toContain('throttle:provider-services');
})->with([
    'the first profile' => ['post', 'provider.store', 'provider.setup'],
    'the edit' => ['put', 'provider.update', 'provider.edit'],
]);

test('the profile pages announce the error of the limit', function (string $path) {
    expect(stpSource($path))->toMatch('/<InputError\s+announce\s+message=\{errors\.profile\}\s*\/>/');
})->with(['resources/js/pages/provider/setup.tsx', 'resources/js/pages/provider/edit.tsx']);

// ---------------------------------------------------------------------------------------------
// #112
// ---------------------------------------------------------------------------------------------

test('one class for the native selects and one for the long button labels, 44 px on phones', function () {
    $classes = stpSource('resources/js/lib/form-classes.ts');

    expect($classes)->toMatch("/export const selectClass =\\s*'[^']*\\bmax-md:min-h-11\\b[^']*';/")
        ->toMatch("/export const wrappingLabel =\\s*'h-auto max-w-full whitespace-normal py-2 text-center';/");

    $files = [
        'resources/js/components/provider-booking-card.tsx', 'resources/js/components/provider-filters-panel.tsx', 'resources/js/components/provider-sort.tsx',
        'resources/js/pages/dashboard.tsx', 'resources/js/pages/provider/availability/index.tsx', 'resources/js/pages/provider/bookings/create.tsx',
        'resources/js/pages/provider/bookings/index.tsx', 'resources/js/pages/provider/services/edit.tsx', 'resources/js/pages/provider/services/index.tsx',
        'resources/js/pages/provider/services/options.tsx',
    ];
    foreach ($files as $file) {
        expect(stpSource($file))->toMatch("/import \\{[^}]*\\b(selectClass|wrappingLabel)\\b[^}]*\\} from '@\\/lib\\/form-classes';/")
            ->not->toMatch('/const (selectClass|wrappingLabel) =/');
    }
});

test('a booking the provider wrote has its own WhatsApp text: registered, not confirmed', function (bool $byProvider, string $text) {
    $booking = stpBooking('+393331234567', ['status' => BookingStatus::Confirmed, 'created_by_provider' => $byProvider]);

    $this->actingAs($booking->provider->user)
        ->get(route('provider.bookings.show', $booking->id))
        ->assertInertia(fn (Assert $page) => $page->where('booking.whatsapp_url', fn (?string $url) => $url !== null && str_contains(rawurldecode($url), $text)));
})->with([
    'written by the provider' => [true, 'Salone Awa ha registrato la tua prenotazione di Treccine'],
    'confirmed from a request' => [false, 'Salone Awa ha confermato la tua prenotazione di Treccine'],
]);

test('the limit of "Aggiungi prenotazione" is announced on its page', function () {
    expect(stpSource('resources/js/pages/provider/bookings/create.tsx'))->toMatch('/<InputError announce message=\{errors\.booking\} \/>/');
    expect(stpSource('app/Providers/AppServiceProvider.php'))->toMatch("/if \\(\\\$field === 'booking'\\) \\{\\s*\\\$exception->redirectTo\\(route\\('provider\\.bookings\\.create'\\)\\);/");
});

test('the categories follow the name shown in the page\'s language, in the list, the home and the provider\'s page', function () {
    $provider = Provider::factory()->published()->create();
    $braids = Category::factory()->create(['slug' => 'treccine', 'name' => 'Treccine']);
    $lashes = Category::factory()->create(['slug' => 'ciglia', 'name' => 'Ciglia']);
    Service::factory()->for($provider)->for($braids)->create();
    Service::factory()->for($provider)->for($lashes)->create();

    // Italian: Ciglia, Treccine. English: Braids, Lashes (the saved Italian names would say the opposite).
    foreach (['it' => ['Ciglia', 'Treccine'], 'en' => ['Braids', 'Lashes']] as $locale => $names) {
        $this->withCookie('locale', $locale)
            ->get(route('providers.index'))
            ->assertInertia(fn (Assert $page) => $page->where('providers.data.0.categories', fn ($categories) => collect($categories)->pluck('name')->all() === $names));

        $this->withCookie('locale', $locale)
            ->get(route('providers.show', $provider->slug))
            ->assertInertia(fn (Assert $page) => $page->where('provider.service_groups', fn ($groups) => collect($groups)->pluck('category.name')->all() === $names));
    }

    foreach (['app/Http/Resources/ProviderResource.php', 'app/Http/Resources/FeaturedProviderResource.php'] as $resource) {
        expect(stpSource($resource))->toContain('->sortBy(fn (Category $category) => $category->sortName())');
    }
});

test('the settings: no heading before the h1 of the page', function () {
    $layout = stpSource('resources/js/layouts/settings/layout.tsx');

    expect($layout)->not->toMatch('/<h[1-6]\b/')
        ->not->toContain('<Heading')
        ->toMatch("/<p className=\"text-xl font-semibold tracking-tight\">\\s*\\{t\\('nav\\.settings'\\)\\}/");

    foreach (['profile', 'security', 'appearance'] as $page) {
        expect(stpSource("resources/js/pages/settings/{$page}.tsx"))->toContain('<h1 className="sr-only">');
    }
});

// ---------------------------------------------------------------------------------------------
// The texts
// ---------------------------------------------------------------------------------------------

test('the new texts in the three languages', function () {
    $expected = [
        'provider.bookings.whatsapp_text_created' => [
            'it' => 'Ciao :name, :provider ha registrato la tua prenotazione di :service per :when.',
            'fr' => 'Bonjour :name, :provider a enregistré votre réservation de :service pour le :when.',
            'en' => 'Hello :name, :provider has registered your booking for :service on :when.',
        ],
        'provider.bookings.validation.service_invalid' => [
            'it' => 'Il servizio scelto non è tra i tuoi servizi attivi.',
            'fr' => 'Le service choisi ne fait pas partie de vos services actifs.',
            'en' => 'The chosen service is not one of your active services.',
        ],
    ];

    foreach ($expected as $key => $texts) {
        foreach ($texts as $locale => $text) {
            $translations = json_decode((string) file_get_contents(lang_path("{$locale}.json")), true);
            expect($translations[$key])->toBe($text);
        }
    }
});
