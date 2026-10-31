<?php

use App\Enums\Weekday;
use App\Models\Availability;
use App\Models\Booking;
use App\Models\Provider;
use App\Models\Service;
use App\Models\ServiceOption;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;

// "Now" is Monday 19 October 2026, 07:00 UTC (09:00 in Italy): the booking window goes from today to
// Friday 18 December. The pages have no JavaScript test runner: the tests on the .tsx files read the
// source, so they break on a rename even when the behaviour is right, and do not prove rendering.
beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-19 07:00', 'UTC'));
    app()->setLocale('it');
});

function pbcProvider(string $name = 'Salone Awa', bool $published = true): Provider
{
    $provider = Provider::factory()->create(['name' => $name, 'is_published' => $published]);
    Availability::factory()->for($provider)->create([
        'weekday' => Weekday::Monday,
        'start_time' => '09:00:00',
        'end_time' => '13:00:00',
    ]);

    return $provider;
}

/**
 * @param  array<string, mixed>  $attributes
 */
function pbcService(Provider $provider, array $attributes = []): Service
{
    return Service::factory()->for($provider)->create(['duration_min' => 30, 'duration_max' => 60, ...$attributes]);
}

function pbcGet(?User $user): TestResponse
{
    $test = $user === null ? test() : test()->actingAs($user);

    return $test->get(route('provider.bookings.create'));
}

/**
 * The props of the page, read with assertInertia().
 *
 * @return array<string, mixed>
 */
function pbcProps(TestResponse $response): array
{
    $props = [];
    $response->assertOk()->assertInertia(function (Assert $page) use (&$props) {
        $page->component('provider/bookings/create');
        $props = $page->toArray()['props'];
    });

    return $props;
}

function pbcSource(string $path): string
{
    return (string) file_get_contents(resource_path($path));
}

// ---------------------------------------------------------------------------------------------
// The page
// ---------------------------------------------------------------------------------------------

test('the page gets the active services by name, with their visible options, the window and the status', function () {
    $provider = pbcProvider();
    $braids = pbcService($provider, ['name' => 'Treccine', 'description' => 'Box braids', 'price' => '60.00']);
    pbcService($provider, ['name' => 'Acconciatura', 'price' => '25.50', 'duration_min' => 45, 'duration_max' => 45]);
    pbcService($provider, ['name' => 'Vecchio servizio', 'is_active' => false]);
    $beads = ServiceOption::factory()->for($braids)->create(['name' => 'Perline', 'extra_price' => '7.50', 'extra_duration' => 15]);
    ServiceOption::factory()->for($braids)->create(['name' => 'Nascosta', 'is_active' => false]);
    $long = ServiceOption::factory()->for($braids)->create(['name' => 'Lunghi', 'extra_price' => '10.00', 'extra_duration' => 30]);

    $props = pbcProps(pbcGet($provider->user));

    expect($props['services'])->toBe([
        [
            'id' => $props['services'][0]['id'],
            'name' => 'Acconciatura',
            'description' => $props['services'][0]['description'],
            'price' => '25.50',
            'duration_min' => 45,
            'duration_max' => 45,
            'options' => [],
        ],
        [
            'id' => $braids->id,
            'name' => 'Treccine',
            'description' => 'Box braids',
            'price' => '60.00',
            'duration_min' => 30,
            'duration_max' => 60,
            // The options by id, the hidden one left out: the same as the public page.
            'options' => [
                ['id' => $beads->id, 'name' => 'Perline', 'extra_price' => '7.50', 'extra_duration' => 15],
                ['id' => $long->id, 'name' => 'Lunghi', 'extra_price' => '10.00', 'extra_duration' => 30],
            ],
        ],
    ]);
    expect($props['bookingWindow'])->toBe(['first_day' => '2026-10-19', 'last_day' => '2026-12-18']);
    expect($props['published'])->toBeTrue();
});

test('the services are the same the public page offers for that provider', function () {
    $provider = pbcProvider();
    $service = pbcService($provider, ['name' => 'Treccine']);
    ServiceOption::factory()->for($service)->create(['name' => 'Perline']);

    $public = [];
    $this->get(route('providers.show', $provider->slug))->assertOk()->assertInertia(function (Assert $page) use (&$public) {
        $public = $page->toArray()['props']['provider']['service_groups'][0]['services'];
    });

    expect(pbcProps(pbcGet($provider->user))['services'])->toBe($public);
});

test('the page never shows another provider\'s services', function () {
    $provider = pbcProvider();
    $mine = pbcService($provider, ['name' => 'Mio servizio']);
    $other = pbcProvider('Barberia Kofi');
    pbcService($other, ['name' => 'Servizio di Kofi']);

    $props = pbcProps(pbcGet($provider->user));

    expect(array_column($props['services'], 'id'))->toBe([$mine->id]);
    expect(json_encode($props))->not->toContain('Servizio di Kofi');
});

test('an unpublished provider gets published false, a provider without active services an empty list', function () {
    $unpublished = pbcProvider(published: false);
    pbcService($unpublished);
    expect(pbcProps(pbcGet($unpublished->user))['published'])->toBeFalse();

    $empty = pbcProvider('Barberia Kofi');
    pbcService($empty, ['is_active' => false]);
    $props = pbcProps(pbcGet($empty->user));
    expect($props['services'])->toBe([])->and($props['published'])->toBeTrue();
});

test('guests, unverified users and users without a provider are sent away', function () {
    pbcGet(null)->assertRedirect(route('login'));

    $unverified = User::factory()->unverified()->create();
    Provider::factory()->create(['user_id' => $unverified->id]);
    pbcGet($unverified)->assertRedirect(route('verification.notice'));

    pbcGet(User::factory()->create())->assertRedirect(route('provider.setup'));
});

test('the page only reads, and its address is the one Wayfinder generates', function () {
    $provider = pbcProvider();

    $this->actingAs($provider->user)->post('/provider/bookings/create')->assertMethodNotAllowed();
    expect(route('provider.bookings.create'))->toBe(url('/provider/bookings/create'));
    expect(Booking::query()->count())->toBe(0);
});

test('the number of queries does not grow with services and options', function () {
    // A provider each: actingAs() keeps the same User object across requests of one user.
    $count = function (int $services, int $options) {
        $provider = pbcProvider("Salone {$services} {$options}");

        for ($i = 1; $i <= $services; $i++) {
            ServiceOption::factory()->count($options)->for(pbcService($provider, ['name' => "Servizio {$i}"]))->create();
        }

        $queries = 0;
        DB::listen(function () use (&$queries) {
            $queries++;
        });
        pbcGet($provider->user)->assertOk();

        return $queries;
    };

    $base = $count(1, 1);

    expect($count(5, 1))->toBe($base);
    expect($count(1, 5))->toBe($base);
    expect($count(5, 5))->toBe($base);
});

// ---------------------------------------------------------------------------------------------
// Messages of the provider's form
// ---------------------------------------------------------------------------------------------

test('the provider form speaks about the customer; the public form keeps its own messages', function () {
    $provider = pbcProvider();
    $service = pbcService($provider);
    $empty = ['service' => $service->id, 'starts_at' => '', 'customer_name' => '', 'customer_phone' => ''];
    $wrong = [
        'service' => $service->id,
        'starts_at' => '2026-10-19T09:00:00Z',
        'customer_name' => str_repeat('a', 151),
        'customer_phone' => '12345',
        'customer_email' => 'non-una-email',
        'notes' => str_repeat('a', 1001),
    ];

    $this->actingAs($provider->user)->post(route('provider.bookings.store'), $empty)->assertSessionHasErrors([
        'customer_name' => 'Scrivi il nome del cliente.',
        'customer_phone' => 'Scrivi un numero di telefono.',
        'starts_at' => 'Scegli un orario.',
    ]);
    $this->actingAs($provider->user)->post(route('provider.bookings.store'), $wrong)->assertSessionHasErrors([
        'customer_name' => 'Il nome può avere al massimo 150 caratteri.',
        'customer_phone' => __('booking.phone.invalid'),
        'customer_email' => "L'indirizzo email non sembra valido.",
        'notes' => 'Le note possono avere al massimo 1000 caratteri.',
    ]);

    auth()->logout();
    $public = route('providers.bookings.store', $provider->slug);
    $this->post($public, $empty)->assertSessionHasErrors([
        'customer_name' => 'Scrivi il tuo nome.',
        'customer_phone' => 'Scrivi un numero di telefono.',
        'starts_at' => 'Scegli un orario.',
    ]);
    $this->post($public, $wrong)->assertSessionHasErrors([
        'customer_name' => 'Il nome può avere al massimo 150 caratteri.',
        'customer_phone' => __('booking.phone.invalid'),
        'customer_email' => "L'indirizzo email non sembra valido.",
        'notes' => 'Le note possono avere al massimo 1000 caratteri.',
    ]);
    expect(Booking::query()->count())->toBe(0);
});

// ---------------------------------------------------------------------------------------------
// Sources
// ---------------------------------------------------------------------------------------------

test('the bookings list links to the new page from its header', function () {
    $source = pbcSource('js/pages/provider/bookings/index.tsx');

    expect(preg_match('/<header\b.*?<\/header>/s', $source, $header))->toBe(1);
    expect($header[0])->toMatch('/<Button\b[^>]*asChild[^>]*>\s*<Link href=\{createBooking\(\)\}>\s*\{t\(\'provider\.bookings\.add\'\)\}\s*<\/Link>/');
    expect($source)->toContain('create as createBooking,');
});

test('the new page sends the provider\'s form and reads the provider\'s slots', function () {
    $source = pbcSource('js/pages/provider/bookings/create.tsx');

    expect($source)
        ->toContain('{...storeBooking.form()}')
        ->toMatch('/import \{[^}]*store as storeBooking,[^}]*\} from \'@\/routes\/provider\/bookings\';/')
        ->toMatch('/: providerSlots\.url\(\{\s*query: \{ service: service\.id, date: day, options: optionIds \},\s*\}\)/')
        // Neither the public routes nor the customer's texts.
        ->not->toMatch("/from '@\/routes\/providers(\/[a-z]+)?';/")
        ->not->toContain('booking.phone.help')
        ->not->toContain('confirm_reference')
        ->toMatch('/<CustomerFields\s+nameLabelKey="provider\.bookings\.create\.name"\s+phoneHelpKey="provider\.bookings\.create\.phone_help"/')
        ->toMatch('/startsAtError=\{errors\.starts_at\}/')
        ->toMatch('/<InputError message=\{errors\.service\} \/>/')
        ->toMatch('/\{processing && <Spinner \/>\}\s*\{t\(\'provider\.bookings\.create\.submit\'\)\}/')
        ->toMatch('/disabled=\{startsAt === \'\' \|\| processing\}/');
});

test('the new page explains instead of showing the form: unpublished first, then no services', function () {
    $source = pbcSource('js/pages/provider/bookings/create.tsx');

    expect($source)->toMatch(
        '/\{!published \? \(\s*<Explanation\s+text=\{t\(\'provider\.bookings\.create\.unpublished\'\)\}\s+link=\{t\(\'provider\.bookings\.create\.unpublished_link\'\)\}\s+href=\{dashboard\(\)\}\s*\/>\s*\) : services\.length === 0 \? \(\s*<Explanation\s+text=\{t\(\'provider\.bookings\.create\.no_services\'\)\}\s+link=\{t\(\'provider\.bookings\.create\.no_services_link\'\)\}\s+href=\{servicesIndex\(\)\}\s*\/>\s*\) : \(\s*<BookingForm\b/',
    );
    expect(substr_count($source, '<BookingForm'))->toBe(1);
});

test('the new page has the three breadcrumbs, as translation keys', function () {
    $source = pbcSource('js/pages/provider/bookings/create.tsx');

    // Since issue #54 the breadcrumbs carry keys: breadcrumbs.tsx translates them.
    expect($source)->toMatch("/titleKey: 'nav\.dashboard',\s*href: dashboard\(\),\s*\},\s*\{\s*titleKey: 'provider\.bookings\.title',\s*href: bookingsIndex\(\),\s*\},\s*\{\s*titleKey: 'provider\.bookings\.create\.title',\s*href: createBooking\(\),/");
});

test('the slots hook gets its address from the caller; the customer panel passes the public one', function () {
    $hook = pbcSource('js/hooks/use-free-slots.ts');
    $sheet = pbcSource('js/components/booking-sheet.tsx');

    expect($hook)
        ->toContain('export function useFreeSlots(url: string | null): UseFreeSlotsReturn')
        ->toContain('}, [url, reloadCount]);')
        ->not->toContain('@/routes');
    expect($sheet)
        ->toMatch('/useFreeSlots\(\s*day === \'\'\s*\? null\s*: slotsRoute\.url\(providerSlug, \{\s*query: \{ service: service\.id, date: day, options: optionIds \},\s*\}\),\s*\)/')
        ->toContain("import { slots as slotsRoute } from '@/routes/providers';")
        // Still the customer's form, with its trap field.
        ->toContain('{...store.form(providerSlug)}')
        ->toContain('name="confirm_reference"');
});

// ---------------------------------------------------------------------------------------------
// Texts
// ---------------------------------------------------------------------------------------------

test('the Italian texts are exactly the expected ones', function () {
    $texts = [
        'provider.bookings.add' => 'Aggiungi prenotazione',
        'provider.bookings.create.title' => 'Nuova prenotazione',
        'provider.bookings.create.help' => 'Per un cliente che ti ha contattato per telefono o di persona. La prenotazione è subito confermata e nessuno riceve email: avvisa tu il cliente.',
        'provider.bookings.create.service' => 'Servizio',
        'provider.bookings.create.choose_service' => 'Scegli un servizio',
        'provider.bookings.create.customer' => 'Dati del cliente',
        'provider.bookings.create.name' => 'Nome del cliente',
        'provider.bookings.create.phone_help' => 'Per esempio +39 333 123 4567. Serve per scrivergli su WhatsApp dalla lista.',
        'provider.bookings.create.submit' => 'Aggiungi la prenotazione',
        'provider.bookings.create.back' => 'Torna alle prenotazioni',
        'provider.bookings.create.unpublished' => 'Pubblica il profilo per aggiungere prenotazioni: finché non è pubblicato non ci sono orari prenotabili.',
        'provider.bookings.create.unpublished_link' => 'Vai alla dashboard',
        'provider.bookings.create.no_services' => 'Non hai servizi attivi: aggiungine uno per poter inserire prenotazioni.',
        'provider.bookings.create.no_services_link' => 'Vai ai tuoi servizi',
        'provider.bookings.validation.name_required' => 'Scrivi il nome del cliente.',
    ];

    foreach ($texts as $key => $text) {
        expect(__($key))->toBe($text);
    }
});
