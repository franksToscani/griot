<?php

use App\Booking\FreeSlots;
use App\Enums\Weekday;
use App\Models\Availability;
use App\Models\Booking;
use App\Models\Category;
use App\Models\Provider;
use App\Models\Service;
use App\Models\ServiceOption;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;

// "Now" is Sunday 18 October 2026, 08:00 UTC. The provider works on Mondays 09:00-13:00 local:
// Monday 19 October has bookable slots from 07:00 UTC.
beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-18 08:00', 'UTC'));
    app()->setLocale('it');
    $this->psmCategory = Category::factory()->create(['name' => 'Treccine', 'slug' => 'treccine']);
});

const PSM_CANNOT_DELETE = 'Questo servizio ha prenotazioni e non si può eliminare: puoi disattivarlo.';

function psmProvider(bool $published = true, string $name = 'Salone Awa'): Provider
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
 * @param  array<string, mixed>  $override
 */
function psmService(Provider $provider, array $override = []): Service
{
    return Service::factory()->for($provider)->for(test()->psmCategory)->create([
        'name' => 'Box braids',
        'price' => '25.50',
        'duration_min' => 60,
        'duration_max' => 60,
        ...$override,
    ]);
}

/**
 * A valid edit form, with the given fields replaced.
 *
 * @param  array<string, mixed>  $override
 * @return array<string, mixed>
 */
function psmForm(array $override = []): array
{
    return [
        'name' => 'Cornrows',
        'description' => 'Treccine aderenti.',
        'category_id' => test()->psmCategory->id,
        'price' => '30,00',
        'duration_min' => 45,
        'duration_max' => 90,
        ...$override,
    ];
}

/**
 * Send one of the five routes for a service id.
 *
 * @param  array<string, mixed>  $data
 */
function psmSend(?User $user, string $route, int|string $serviceId, array $data = []): TestResponse
{
    $test = $user === null ? test() : test()->actingAs($user);
    $url = route($route, ['service' => $serviceId]);

    return match ($route) {
        'provider.services.edit' => $test->get($url),
        'provider.services.update' => $test->put($url, $data === [] ? psmForm() : $data),
        'provider.services.destroy' => $test->delete($url),
        default => $test->post($url, $data),
    };
}

/**
 * The columns that must not change when an action is refused.
 *
 * @return array<string, mixed>
 */
function psmColumns(Service $service): array
{
    return $service->fresh()->only(['provider_id', 'name', 'price', 'duration_min', 'duration_max', 'is_active', 'category_id', 'description']);
}

/**
 * Run $run with a "deleting" listener on Service, then remove it in any case. Service has no
 * listeners of its own, so flushEventListeners() removes only this one.
 */
function psmWhileDeleting(Closure $listener, Closure $run): mixed
{
    Service::deleting($listener);

    try {
        return $run();
    } finally {
        Service::flushEventListeners();
    }
}

dataset('psm routes', [
    'edit' => ['provider.services.edit'],
    'update' => ['provider.services.update'],
    'deactivate' => ['provider.services.deactivate'],
    'activate' => ['provider.services.activate'],
    'destroy' => ['provider.services.destroy'],
]);

test('guests, unverified users and users without a provider are sent away', function (string $route) {
    $service = psmService(psmProvider());

    psmSend(null, $route, $service->id)->assertRedirect(route('login'));

    $unverified = User::factory()->unverified()->create();
    Provider::factory()->create(['user_id' => $unverified->id]);
    psmSend($unverified, $route, $service->id)->assertRedirect(route('verification.notice'));

    // A valid body for the PUT: invalid data would get the validation errors first.
    psmSend(User::factory()->create(), $route, $service->id)->assertRedirect(route('provider.setup'));

    expect(Service::query()->whereKey($service->id)->exists())->toBeTrue();
})->with('psm routes');

test('a service of another provider gets a 404 and nothing changes', function (string $route) {
    $theirs = psmService(psmProvider(name: 'Barberia Kofi'), ['is_active' => $route !== 'provider.services.activate']);
    $before = psmColumns($theirs);
    $mine = psmProvider();

    psmSend($mine->user, $route, $theirs->id)->assertNotFound();

    expect(Service::query()->whereKey($theirs->id)->exists())->toBeTrue();
    expect(psmColumns($theirs))->toBe($before);
})->with('psm routes');

test('a missing id gets the same 404, a non numeric one too', function (string $route) {
    $provider = psmProvider();

    psmSend($provider->user, $route, 999999)->assertNotFound();
    psmSend($provider->user, $route, 'abc')->assertNotFound();
})->with('psm routes');

test('the edit page has the eight keys, the categories and has_bookings', function (bool $withBooking) {
    $provider = psmProvider();
    $service = psmService($provider, ['description' => null]);

    if ($withBooking) {
        Booking::factory()->for($provider)->for($service)->create();
    }

    $this->actingAs($provider->user)
        ->get(route('provider.services.edit', $service->id))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('provider/services/edit')
            ->where('service', [
                'id' => $service->id,
                'name' => 'Box braids',
                'description' => null,
                'category' => ['id' => $this->psmCategory->id, 'name' => 'Treccine'],
                'price' => '25.50',
                'duration_min' => 60,
                'duration_max' => 60,
                'is_active' => true,
            ])
            ->has('categories', 1)
            ->where('bookings', ['has_bookings' => $withBooking]));
})->with([
    'without bookings' => [false],
    'with a booking' => [true],
]);

test('a valid edit changes the fields, not is_active nor provider_id', function () {
    $provider = psmProvider();
    $service = psmService($provider, ['is_active' => false]);

    $this->actingAs($provider->user)
        ->put(route('provider.services.update', $service->id), psmForm())
        ->assertRedirect(route('provider.services.index'))
        ->assertSessionHasNoErrors()
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Servizio aggiornato.']);

    expect(psmColumns($service))->toBe([
        'provider_id' => $provider->id,
        'name' => 'Cornrows',
        'price' => '30.00',
        'duration_min' => 45,
        'duration_max' => 90,
        'is_active' => false,
        'category_id' => $this->psmCategory->id,
        'description' => 'Treccine aderenti.',
    ]);
});

test('provider_id, is_active and id in the body have no effect', function () {
    $provider = psmProvider();
    $service = psmService($provider);
    $other = psmProvider(name: 'Barberia Kofi');

    $this->actingAs($provider->user)
        ->put(route('provider.services.update', $service->id), psmForm(['provider_id' => $other->id, 'is_active' => '0', 'id' => 999]))
        ->assertSessionHasNoErrors();

    $fresh = $service->fresh();
    expect($fresh->provider_id)->toBe($provider->id);
    expect($fresh->is_active)->toBeTrue();
    expect(Service::query()->whereKey(999)->exists())->toBeFalse();
    expect($other->services()->count())->toBe(0);
});

test('the price shown with a comma is saved back unchanged', function (Closure $shown) {
    $provider = psmProvider();
    $service = psmService($provider, ['price' => '25.50']);

    $price = $this->actingAs($provider->user)->get(route('provider.services.edit', $service->id))->viewData('page')['props']['service']['price'];
    expect($price)->toBe('25.50');

    $this->actingAs($provider->user)
        ->put(route('provider.services.update', $service->id), psmForm(['price' => $shown($price)]))
        ->assertSessionHasNoErrors();

    expect($service->fresh()->price)->toBe('25.50');
})->with([
    // What the page puts in the field (priceForInput): "25,50".
    'with a comma' => [fn () => fn (string $price) => str_replace('.', ',', $price)],
    'with a dot' => [fn () => fn (string $price) => $price],
]);

test('the price rules apply to the edit too', function (string $price, ?string $saved) {
    $provider = psmProvider();
    $service = psmService($provider);

    $response = $this->actingAs($provider->user)->put(route('provider.services.update', $service->id), psmForm(['price' => $price]));

    if ($saved === null) {
        $response->assertSessionHasErrors(['price' => 'Scrivi un prezzo valido tra 0,01 e 9999,99 euro, per esempio 25 oppure 25,50.']);
        expect($service->fresh()->price)->toBe('25.50');
    } else {
        $response->assertSessionHasNoErrors();
        expect($service->fresh()->price)->toBe($saved);
    }
})->with([
    ['25,50', '25.50'],
    ['25.5', '25.50'],
    ['0,01', '0.01'],
    ['9999,99', '9999.99'],
    ['1e3', null],
    ['0', null],
    ['10000', null],
    ['1.000,50', null],
]);

test('the duration rules apply to the edit too', function () {
    $provider = psmProvider();
    $service = psmService($provider);
    $url = route('provider.services.update', $service->id);

    $this->actingAs($provider->user)->put($url, psmForm(['duration_min' => 7]))
        ->assertSessionHasErrors(['duration_min' => 'Scrivi la durata in minuti, da 5 a 720, a passi di 5.']);
    $this->actingAs($provider->user)->put($url, psmForm(['duration_min' => 120, 'duration_max' => 60]))
        ->assertSessionHasErrors(['duration_max' => 'La durata massima non può essere minore della minima.']);

    expect($service->fresh()->only(['duration_min', 'duration_max']))->toBe(['duration_min' => 60, 'duration_max' => 60]);
});

test('editing a service does not change its existing bookings', function () {
    $provider = psmProvider();
    $service = psmService($provider);
    $option = ServiceOption::factory()->for($service)->create();
    $booking = Booking::factory()->for($provider)->for($service)->create([
        'starts_at' => CarbonImmutable::parse('2026-10-26 07:00', 'UTC'),
        'ends_at' => CarbonImmutable::parse('2026-10-26 08:00', 'UTC'),
    ]);
    $booking->options()->attach($option->id);

    $this->actingAs($provider->user)
        ->put(route('provider.services.update', $service->id), psmForm(['duration_min' => 120, 'duration_max' => 180]))
        ->assertSessionHasNoErrors();

    $fresh = $booking->fresh();
    expect($fresh->starts_at->toIso8601String())->toBe('2026-10-26T07:00:00+00:00');
    expect($fresh->ends_at->toIso8601String())->toBe('2026-10-26T08:00:00+00:00');
    expect($fresh->options()->pluck('service_options.id')->all())->toBe([$option->id]);
});

test('deactivate and activate, both idempotent', function () {
    $provider = psmProvider();
    $service = psmService($provider);

    $this->actingAs($provider->user)
        ->post(route('provider.services.deactivate', $service->id))
        ->assertRedirect(route('provider.services.index'))
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Servizio disattivato: i clienti non lo vedono più.']);
    expect($service->fresh()->is_active)->toBeFalse();

    $updatedAt = $service->fresh()->updated_at->toIso8601String();
    $this->travel(1)->hour();
    $this->actingAs($provider->user)->post(route('provider.services.deactivate', $service->id))->assertRedirect(route('provider.services.index'));
    expect($service->fresh()->updated_at->toIso8601String())->toBe($updatedAt);

    $this->actingAs($provider->user)
        ->post(route('provider.services.activate', $service->id))
        ->assertRedirect(route('provider.services.index'))
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Servizio attivato: i clienti lo vedono di nuovo.']);
    expect($service->fresh()->is_active)->toBeTrue();

    $updatedAt = $service->fresh()->updated_at->toIso8601String();
    $this->travel(1)->hour();
    $this->actingAs($provider->user)->post(route('provider.services.activate', $service->id))->assertRedirect(route('provider.services.index'));
    expect($service->fresh()->updated_at->toIso8601String())->toBe($updatedAt);
});

test('a deactivated service disappears from the page, the slots and the form, but its bookings stay', function () {
    $provider = psmProvider();
    $service = psmService($provider, ['name' => 'Servizio Da Spegnere']);
    $booking = Booking::factory()->for($provider)->for($service)->create([
        'starts_at' => CarbonImmutable::parse('2026-10-26 07:00', 'UTC'),
        'ends_at' => CarbonImmutable::parse('2026-10-26 08:00', 'UTC'),
    ]);
    $monday = CarbonImmutable::parse('2026-10-19', 'Europe/Rome');

    // The five consequences, checked as a customer (a guest), before and after.
    $check = function (bool $active) use ($provider, $service, $booking, $monday) {
        app('auth')->forgetGuards();

        // (a) The public page.
        $groups = $this->get(route('providers.show', $provider->slug))->assertOk()->viewData('page')['props']['provider']['service_groups'];
        $names = collect($groups)->flatMap(fn (array $group) => collect($group['services'])->pluck('name'))->all();
        expect(in_array('Servizio Da Spegnere', $names, true))->toBe($active);

        // (b) FreeSlots.
        $slots = app(FreeSlots::class)->slotsFor($provider->fresh(), $service->fresh(), [], $monday);
        expect($slots->isNotEmpty())->toBe($active);

        // (c) The slots endpoint: 200 in both cases, with or without slots.
        $json = $this->getJson(route('providers.slots', ['publishedProvider' => $provider->slug, 'service' => $service->id, 'date' => '2026-10-19']))->assertOk()->json('slots');
        expect($json !== [])->toBe($active);

        // (e) The private page of an existing booking.
        $this->get(route('bookings.show', ['booking' => $booking->public_token]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('booking.service.name', 'Servizio Da Spegnere'));
    };

    $check(true);

    $this->actingAs($provider->user)->post(route('provider.services.deactivate', $service->id))->assertSessionHasNoErrors();
    $check(false);

    // (d) The booking form: nothing is created. The message is the "slot taken" one, misleading
    // for a deactivated service: a known debt (ProviderBookingController and CreateBooking are
    // outside this issue).
    app('auth')->forgetGuards();
    $this->post(route('providers.bookings.store', $provider->slug), [
        'service' => $service->id,
        'starts_at' => '2026-10-19T07:00:00Z',
        'customer_name' => 'Awa Diop',
        'customer_phone' => '+39 333 123 4567',
    ])->assertSessionHasErrors(['starts_at' => 'Questo orario è appena stato prenotato da qualcun altro. Scegli un altro orario.']);
    expect($service->bookings()->count())->toBe(1);

    $this->actingAs($provider->user)->post(route('provider.services.activate', $service->id))->assertSessionHasNoErrors();
    $check(true);
});

test('a service without bookings is deleted with its options', function () {
    $provider = psmProvider();
    $service = psmService($provider);
    $option = ServiceOption::factory()->for($service)->create();

    $this->actingAs($provider->user)
        ->delete(route('provider.services.destroy', $service->id))
        ->assertRedirect(route('provider.services.index'))
        ->assertSessionHasNoErrors()
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Servizio eliminato.']);

    expect(Service::query()->whereKey($service->id)->exists())->toBeFalse();
    expect(ServiceOption::query()->whereKey($option->id)->exists())->toBeFalse();
});

test('a service with a booking in any status is not deleted', function (string $status) {
    $provider = psmProvider();
    $service = psmService($provider);
    Booking::factory()->for($provider)->for($service)->create(['status' => $status]);
    $before = psmColumns($service);

    $this->actingAs($provider->user)
        ->delete(route('provider.services.destroy', $service->id))
        ->assertRedirect(route('provider.services.edit', $service->id))
        ->assertSessionHasErrors(['service' => PSM_CANNOT_DELETE])
        ->assertInertiaFlashMissing('toast');

    expect(psmColumns($service))->toBe($before);
})->with(['pending', 'confirmed', 'cancelled']);

test('a service whose option was chosen in a booking is not deleted', function () {
    $provider = psmProvider();
    $service = psmService($provider);
    $option = ServiceOption::factory()->for($service)->create();
    $booking = Booking::factory()->for($provider)->for(psmService($provider, ['name' => 'Altro']))->create();
    DB::table('booking_service_option')->insert(['booking_id' => $booking->id, 'service_option_id' => $option->id]);

    $this->actingAs($provider->user)
        ->delete(route('provider.services.destroy', $service->id))
        ->assertSessionHasErrors(['service' => PSM_CANNOT_DELETE]);

    expect(Service::query()->whereKey($service->id)->exists())->toBeTrue();
});

test('a booking arriving just before the delete gets the message, not a 500', function () {
    $provider = psmProvider();
    $service = psmService($provider);
    $inserted = false;

    psmWhileDeleting(function (Service $deleting) use (&$inserted) {
        // Only the first time: a customer books just before the row is deleted.
        if (! $inserted) {
            $inserted = true;
            DB::table('bookings')->insert([
                'provider_id' => $deleting->provider_id,
                'service_id' => $deleting->id,
                'customer_name' => 'Cliente',
                'customer_phone' => '+39 333 000 0000',
                'starts_at' => '2026-10-26 07:00:00',
                'ends_at' => '2026-10-26 08:00:00',
                'status' => 'pending',
                'public_token' => Str::random(48),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }, fn () => $this->actingAs($provider->user)
        ->delete(route('provider.services.destroy', $service->id))
        ->assertRedirect(route('provider.services.edit', $service->id))
        ->assertSessionHasErrors(['service' => PSM_CANNOT_DELETE]));

    expect($inserted)->toBeTrue();
    expect(Service::query()->whereKey($service->id)->exists())->toBeTrue();
});

test('the deleting listener above is not registered any more', function () {
    expect(Event::hasListeners('eloquent.deleting: '.Service::class))->toBeFalse();
});

test('the number of queries of the edit page does not grow with the bookings', function () {
    // Three providers, each with its own user: actingAs() keeps the same User object, so a second
    // request of the same user would find user and provider already loaded and count less.
    $count = function (int $bookings) {
        $provider = psmProvider(name: "Salone {$bookings}");
        $service = psmService($provider);

        for ($day = 0; $day < $bookings; $day++) {
            Booking::factory()->for($provider)->for($service)->create([
                'starts_at' => CarbonImmutable::parse('2026-11-02 07:00', 'UTC')->addDays($day),
                'ends_at' => CarbonImmutable::parse('2026-11-02 08:00', 'UTC')->addDays($day),
            ]);
        }

        $queries = 0;
        DB::listen(function () use (&$queries) {
            $queries++;
        });
        $this->actingAs($provider->user)->get(route('provider.services.edit', $service->id))->assertOk();

        return $queries;
    };

    $none = $count(0);
    $one = $count(1);
    $five = $count(5);

    // Not growing: one booking or five, the same. Without bookings blockedBy() asks one more
    // exists() (the booked options), a fixed difference.
    expect($five)->toBe($one);
    expect($none)->toBe($one + 1);
});

test('the 31st action in a minute of the same user is refused', function () {
    $provider = psmProvider();
    $service = psmService($provider);

    // Deactivate and activate share the counter with update and destroy: 30 in all.
    for ($i = 1; $i <= 15; $i++) {
        $this->actingAs($provider->user)->post(route('provider.services.deactivate', $service->id))->assertSessionHasNoErrors();
        $this->actingAs($provider->user)->post(route('provider.services.activate', $service->id))->assertSessionHasNoErrors();
    }
    $before = psmColumns($service);

    $this->actingAs($provider->user)
        ->put(route('provider.services.update', $service->id), psmForm())
        ->assertRedirect(route('provider.services.index'))
        ->assertSessionHasErrors(['services' => 'Hai fatto troppe richieste in poco tempo. Riprova tra qualche minuto.']);
    expect(psmColumns($service))->toBe($before);

    $other = psmProvider(name: 'Barberia Kofi');
    $theirs = psmService($other);
    $this->actingAs($other->user)->post(route('provider.services.deactivate', $theirs->id))->assertSessionHasNoErrors();
    expect($theirs->fresh()->is_active)->toBeFalse();
});

test('the Italian texts are exactly the expected ones', function () {
    expect(__('provider.services.updated'))->toBe('Servizio aggiornato.');
    expect(__('provider.services.deactivated'))->toBe('Servizio disattivato: i clienti non lo vedono più.');
    expect(__('provider.services.activated'))->toBe('Servizio attivato: i clienti lo vedono di nuovo.');
    expect(__('provider.services.deleted'))->toBe('Servizio eliminato.');
    expect(__('provider.services.cannot_delete'))->toBe(PSM_CANNOT_DELETE);
    expect(__('provider.services.edit_button'))->toBe('Modifica');
    expect(__('provider.services.edit_title'))->toBe('Modifica il servizio');
    expect(__('provider.services.edit_description'))->toBe('Cambia i dati di questo servizio. Le prenotazioni già fatte non cambiano.');
    expect(__('provider.services.form.save'))->toBe('Salva le modifiche');
    expect(__('provider.services.state_title'))->toBe('Stato del servizio');
    expect(__('provider.services.deactivate'))->toBe('Disattiva il servizio');
    expect(__('provider.services.deactivate_title'))->toBe('Vuoi disattivare il servizio?');
    expect(__('provider.services.deactivate_description'))->toBe('I clienti non lo vedranno più e non potranno prenotarlo. Le prenotazioni già fatte restano. Potrai attivarlo di nuovo.');
    expect(__('provider.services.deactivate_confirm'))->toBe('Sì, disattivalo');
    expect(__('provider.services.deactivate_cancel'))->toBe('No, lascialo attivo');
    expect(__('provider.services.activate'))->toBe('Attiva il servizio');
    expect(__('provider.services.delete_title'))->toBe('Elimina il servizio');
    expect(__('provider.services.delete_button'))->toBe('Elimina il servizio');
    expect(__('provider.services.delete_confirm_title'))->toBe('Vuoi eliminare il servizio?');
    expect(__('provider.services.delete_confirm_description'))->toBe("Il servizio e le sue opzioni verranno cancellati. L'operazione non si può annullare.");
    expect(__('provider.services.delete_confirm_yes'))->toBe('Sì, eliminalo');
    expect(__('provider.services.delete_confirm_no'))->toBe('No, tienilo');
    expect(__('provider.services.delete_blocked'))->toBe('Questo servizio ha prenotazioni, quindi non si può eliminare. Puoi disattivarlo: sparirà dal sito ma le prenotazioni restano.');
});
