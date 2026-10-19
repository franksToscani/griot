<?php

use App\Enums\BookingStatus;
use App\Enums\Weekday;
use App\Models\Availability;
use App\Models\Booking;
use App\Models\Provider;
use App\Models\Service;
use App\Models\ServiceOption;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;

// "Now" is Sunday 18 October 2026, 08:00 UTC (10:00 in Italy, summer time UTC+2). On Monday
// 19 October the 09:00-13:00 local window is 07:00-11:00 UTC; the service lasts 60 minutes.
const PB_START = '2026-10-19T07:00:00Z';

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-18 08:00', 'UTC'));
    app()->setLocale('it');
});

function pbProvider(): Provider
{
    $provider = Provider::factory()->published()->create();
    Availability::factory()->for($provider)->create([
        'weekday' => Weekday::Monday,
        'start_time' => '09:00:00',
        'end_time' => '13:00:00',
    ]);

    return $provider;
}

function pbService(Provider $provider, int $minutes = 60): Service
{
    return Service::factory()->for($provider)->create(['duration_min' => $minutes, 'duration_max' => $minutes]);
}

/**
 * A complete, valid form for the service, with the given fields replaced.
 *
 * @param  array<string, mixed>  $override
 * @return array<string, mixed>
 */
function pbForm(Service $service, array $override = []): array
{
    return [
        'service' => $service->id,
        'starts_at' => PB_START,
        'customer_name' => 'Awa Diop',
        'customer_phone' => '+39 333 123 4567',
        ...$override,
    ];
}

/**
 * Send the form of the provider page.
 *
 * @param  array<string, mixed>  $data
 */
function pbPost(Provider|string $provider, array $data)
{
    $slug = $provider instanceof Provider ? $provider->slug : $provider;

    return test()->post(route('providers.bookings.store', $slug), $data);
}

test('a valid form creates a pending booking and goes to its private page with a message', function () {
    $provider = pbProvider();
    $service = pbService($provider);
    $option = ServiceOption::factory()->for($service)->create(['extra_duration' => 30]);

    $response = pbPost($provider, pbForm($service, [
        'options' => [$option->id, $option->id],
        'customer_email' => 'awa@example.com',
        'notes' => 'Porto io le extension.',
    ]));
    $booking = Booking::query()->sole();

    $response
        ->assertRedirect(route('bookings.show', ['booking' => $booking->public_token]))
        ->assertSessionHasNoErrors()
        ->assertInertiaFlash('toast', [
            'type' => 'success',
            'message' => 'Richiesta inviata. Il professionista deve ancora confermarla.',
        ]);
    // The address ends with the secret code, not with the id.
    expect($response->headers->get('Location'))
        ->toEndWith('/bookings/'.$booking->public_token)
        ->not->toEndWith('/bookings/'.$booking->id);

    // Following the redirect, the private page shows the booking just created.
    $this->get($response->headers->get('Location'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('bookings/show')
            ->where('booking.status', 'pending')
            ->where('booking.provider.slug', $provider->slug)
            ->where('booking.service.name', $service->name)
            ->where('booking.options', [['name' => $option->name, 'extra_price' => $option->extra_price]])
            ->where('booking.local_date', '2026-10-19')
            ->where('booking.local_start', '09:00')
            ->where('booking.local_end', '10:30'));

    expect($booking->status)->toBe(BookingStatus::Pending);
    expect($booking->provider_id)->toBe($provider->id);
    expect($booking->service_id)->toBe($service->id);
    expect($booking->starts_at->toIso8601String())->toBe('2026-10-19T07:00:00+00:00');
    // 60 minutes of the service plus 30 of the option.
    expect($booking->ends_at->toIso8601String())->toBe('2026-10-19T08:30:00+00:00');
    expect($booking->customer_name)->toBe('Awa Diop');
    expect($booking->customer_phone)->toBe('+39 333 123 4567');
    expect($booking->customer_email)->toBe('awa@example.com');
    expect($booking->notes)->toBe('Porto io le extension.');
    expect($booking->public_token)->toHaveLength(48);
    expect($booking->options()->pluck('service_options.id')->all())->toBe([$option->id]);
});

test('an unpublished or missing provider gives a 404, even with wrong or missing fields', function () {
    $unpublished = pbProvider();
    $unpublished->update(['is_published' => false]);
    $service = pbService($unpublished);

    pbPost($unpublished, pbForm($service))->assertNotFound();
    pbPost('non-esiste', pbForm($service))->assertNotFound();
    pbPost($unpublished, [])->assertNotFound();
    pbPost('non-esiste', ['starts_at' => 'domani'])->assertNotFound();
    expect(Booking::query()->count())->toBe(0);
});

test('missing required fields have Italian messages', function () {
    $provider = pbProvider();

    pbPost($provider, [])
        ->assertSessionHasErrors([
            'starts_at' => 'Scegli un orario.',
            'customer_name' => 'Scrivi il tuo nome.',
            'customer_phone' => 'Scrivi un numero di telefono.',
        ])
        // A missing service is a client error: default message, but still an error.
        ->assertSessionHasErrors('service');
    expect(Booking::query()->count())->toBe(0);
});

test('a field in the wrong format is rejected on that field', function (array $override, string $field) {
    $provider = pbProvider();
    $service = pbService($provider);

    pbPost($provider, pbForm($service, $override))->assertSessionHasErrors($field);
    expect(Booking::query()->count())->toBe(0);
})->with([
    'local start' => [['starts_at' => '2026-10-19 09:00'], 'starts_at'],
    'start with offset' => [['starts_at' => '2026-10-19T09:00:00+02:00'], 'starts_at'],
    'impossible start' => [['starts_at' => '2026-02-30T07:00:00Z'], 'starts_at'],
    'invalid email' => [['customer_email' => 'non-una-email'], 'customer_email'],
    'name too long' => [['customer_name' => str_repeat('a', 151)], 'customer_name'],
    'phone with letters' => [['customer_phone' => '333 abc 4567'], 'customer_phone'],
    'phone with 5 digits' => [['customer_phone' => '12345'], 'customer_phone'],
    'phone with 16 digits' => [['customer_phone' => '1234567890123456'], 'customer_phone'],
    'notes too long' => [['notes' => str_repeat('x', 1001)], 'notes'],
    'options not an array' => [['options' => '3'], 'options'],
    'more than 20 options' => [['options' => range(1, 21)], 'options'],
    'options not integers' => [['options' => ['abc']], 'options.0'],
    'service not an integer' => [['service' => 'abc'], 'service'],
]);

test('the customer messages for formats are the Italian ones', function () {
    $provider = pbProvider();
    $service = pbService($provider);

    pbPost($provider, pbForm($service, [
        'customer_name' => str_repeat('a', 151),
        'customer_phone' => '12345',
        'customer_email' => 'non-una-email',
        'notes' => str_repeat('x', 1001),
    ]))->assertSessionHasErrors([
        'customer_name' => 'Il nome può avere al massimo 150 caratteri.',
        'customer_phone' => 'Il numero di telefono non sembra valido: controlla le cifre.',
        'customer_email' => "L'indirizzo email non sembra valido.",
        'notes' => 'Le note possono avere al massimo 1000 caratteri.',
    ]);
});

test('a time taken by a pending or confirmed booking is refused, a cancelled one does not block', function (BookingStatus $status, bool $blocks) {
    $provider = pbProvider();
    $service = pbService($provider);
    Booking::factory()->for($provider)->for($service)->create([
        'starts_at' => CarbonImmutable::parse('2026-10-19 07:00', 'UTC'),
        'ends_at' => CarbonImmutable::parse('2026-10-19 08:00', 'UTC'),
        'status' => $status,
    ]);

    $response = pbPost($provider, pbForm($service));

    if ($blocks) {
        $response->assertSessionHasErrors([
            'starts_at' => 'Questo orario è appena stato prenotato da qualcun altro. Scegli un altro orario.',
        ]);
        expect(Booking::query()->count())->toBe(1);
    } else {
        $response->assertSessionHasNoErrors();
        expect(Booking::query()->count())->toBe(2);
    }
})->with([
    'pending' => [BookingStatus::Pending, true],
    'confirmed' => [BookingStatus::Confirmed, true],
    'cancelled' => [BookingStatus::Cancelled, false],
]);

test('a time that is not a free slot gets the same error on starts_at', function (string $startsAt, ?string $now) {
    if ($now !== null) {
        $this->travelTo(CarbonImmutable::parse($now, 'UTC'));
    }
    $provider = pbProvider();
    $service = pbService($provider);

    pbPost($provider, pbForm($service, ['starts_at' => $startsAt]))
        ->assertSessionHasErrors(['starts_at' => 'Questo orario è appena stato prenotato da qualcun altro. Scegli un altro orario.']);
    expect(Booking::query()->count())->toBe(0);
})->with([
    // 09:10 local: the grid starts at 09:00 every 30 minutes.
    'off the grid' => ['2026-10-19T07:10:00Z', null],
    // 14:00 local: the window ends at 13:00.
    'outside the window' => ['2026-10-19T12:00:00Z', null],
    // Monday 12 October is in the past.
    'in the past' => ['2026-10-12T07:00:00Z', null],
    // Now is Monday 10:10 local: with 120 minutes of notice, 11:00 local is too soon.
    'inside the minimum notice' => ['2026-10-19T09:00:00Z', '2026-10-19 08:10'],
    // Monday 21 December, 09:00 local in winter: beyond 17 December, the last day.
    'beyond the booking window' => ['2026-12-21T08:00:00Z', null],
]);

test('a service of another provider or a missing service get the same error', function () {
    $provider = pbProvider();
    $otherService = pbService(pbProvider());
    $message = 'Il servizio scelto non è disponibile per questo professionista.';

    pbPost($provider, pbForm($otherService))->assertSessionHasErrors(['service' => $message]);
    pbPost($provider, ['service' => 999999] + pbForm($otherService))->assertSessionHasErrors(['service' => $message]);
    expect(Booking::query()->count())->toBe(0);
});

test('options that are not options of the service are rejected', function () {
    $provider = pbProvider();
    $service = pbService($provider);
    $valid = ServiceOption::factory()->for($service)->create();
    $otherService = ServiceOption::factory()->for(pbService($provider))->create();
    $otherProvider = ServiceOption::factory()->for(pbService(pbProvider()))->create();

    foreach ([[$otherService->id], [$otherProvider->id], [999999], [$valid->id, $otherService->id]] as $options) {
        pbPost($provider, pbForm($service, ['options' => $options]))
            ->assertSessionHasErrors(['options' => 'Alcune opzioni scelte non appartengono a questo servizio.']);
    }
    expect(Booking::query()->count())->toBe(0);
});

test('provider_id, status, public_token and ends_at in the input are ignored', function () {
    $provider = pbProvider();
    $service = pbService($provider);
    $otherProvider = pbProvider();

    pbPost($provider, pbForm($service, [
        'provider_id' => $otherProvider->id,
        'status' => BookingStatus::Confirmed->value,
        'public_token' => 'scelto-dal-cliente',
        'ends_at' => '2026-10-19T12:00:00Z',
    ]))->assertSessionHasNoErrors();

    $booking = Booking::query()->sole();
    expect($booking->provider_id)->toBe($provider->id);
    expect($booking->status)->toBe(BookingStatus::Pending);
    expect($booking->public_token)->not->toBe('scelto-dal-cliente');
    expect($booking->public_token)->toHaveLength(48);
    expect($booking->ends_at->toIso8601String())->toBe('2026-10-19T08:00:00+00:00');
});

test('the token is only in the redirect address and in private_url, never in the flash message', function () {
    $provider = pbProvider();
    $service = pbService($provider);

    $response = pbPost($provider, pbForm($service));
    $token = Booking::query()->sole()->public_token;

    expect($response->headers->get('Location'))->toEndWith('/bookings/'.$token);
    // After the POST the session holds the flash message, not the address: no previous URL is
    // stored for a POST.
    expect(json_encode(session()->all()))->not->toContain($token);

    $page = $this->get($response->headers->get('Location'))->assertOk()->viewData('page');
    expect($page['props']['private_url'])->toEndWith('/bookings/'.$token);
    // Without its address and private_url, the page (props and flash) does not hold the token.
    unset($page['url'], $page['props']['private_url']);
    expect(json_encode($page))->not->toContain($token);
});

test('email and notes can be missing, name and phone are trimmed', function () {
    $provider = pbProvider();
    $service = pbService($provider);

    pbPost($provider, pbForm($service, [
        'customer_name' => '   Awa Diop   ',
        'customer_phone' => '  +39 333 123 4567  ',
        'customer_email' => '',
        'notes' => '',
    ]))->assertSessionHasNoErrors();

    $booking = Booking::query()->sole();
    expect($booking->customer_name)->toBe('Awa Diop');
    expect($booking->customer_phone)->toBe('+39 333 123 4567');
    expect($booking->customer_email)->toBeNull();
    expect($booking->notes)->toBeNull();
});

test('the provider page exposes the booking window in local calendar days', function () {
    // 23:30 UTC on 28 March is 00:30 on 29 March in Italy: today is the 29th.
    $this->travelTo(CarbonImmutable::parse('2026-03-28 23:30', 'UTC'));
    $provider = pbProvider();

    // 29 March plus 60 calendar days is 28 May.
    $this->get(route('providers.show', $provider->slug))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('bookingWindow', ['first_day' => '2026-03-29', 'last_day' => '2026-05-28']));
});

test('the server messages are the expected Italian texts', function () {
    expect(__('booking.validation.starts_at_required'))->toBe('Scegli un orario.');
    expect(__('booking.validation.name_required'))->toBe('Scrivi il tuo nome.');
    expect(__('booking.validation.name_max'))->toBe('Il nome può avere al massimo :max caratteri.');
    expect(__('booking.validation.phone_required'))->toBe('Scrivi un numero di telefono.');
    expect(__('booking.validation.phone_invalid'))->toBe('Il numero di telefono non sembra valido: controlla le cifre.');
    expect(__('booking.validation.email_invalid'))->toBe("L'indirizzo email non sembra valido.");
    expect(__('booking.validation.notes_max'))->toBe('Le note possono avere al massimo :max caratteri.');
    expect(__('booking.create.slot_taken'))->toBe('Questo orario è appena stato prenotato da qualcun altro. Scegli un altro orario.');
    expect(__('booking.create.created'))->toBe('Richiesta inviata. Il professionista deve ancora confermarla.');
});

test('option ids sent as strings, as the browser form does, are accepted', function () {
    $provider = pbProvider();
    $service = pbService($provider);
    $option = ServiceOption::factory()->for($service)->create(['extra_duration' => 30]);

    // The Inertia form sends {"options": ["<id>"]}: the ids are strings.
    $this->postJson(route('providers.bookings.store', $provider->slug), pbForm($service, ['options' => [(string) $option->id]]))
        ->assertRedirect(route('bookings.show', ['booking' => Booking::query()->sole()->public_token]));

    expect(Booking::query()->sole()->options()->pluck('service_options.id')->all())->toBe([$option->id]);
});
