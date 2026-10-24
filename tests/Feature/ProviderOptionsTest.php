<?php

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
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;

// "Now" is Sunday 18 October 2026, 08:00 UTC. The providers work on Mondays 09:00-13:00 local:
// Monday 19 October has bookable slots from 07:00 UTC.
beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-18 08:00', 'UTC'));
    app()->setLocale('it');
    $this->popCategory = Category::factory()->create(['name' => 'Treccine', 'slug' => 'treccine']);
});

const POP_PRICE_INVALID = 'Scrivi un prezzo valido tra 0 e 999,99 euro, per esempio 5 oppure 7,50.';
const POP_DURATION_INVALID = 'Scrivi i minuti da 0 a 240, a passi di 5.';
const POP_CANNOT_DELETE = 'Questa opzione è già stata scelta in alcune prenotazioni e non si può eliminare.';

function popProvider(bool $published = true, string $name = 'Salone Awa'): Provider
{
    $provider = Provider::factory()->create(['name' => $name, 'is_published' => $published]);
    Availability::factory()->for($provider)->create([
        'weekday' => Weekday::Monday,
        'start_time' => '09:00:00',
        'end_time' => '13:00:00',
    ]);

    return $provider;
}

function popService(Provider $provider, string $name = 'Box braids'): Service
{
    return Service::factory()->for($provider)->for(test()->popCategory)->create([
        'name' => $name,
        'duration_min' => 60,
        'duration_max' => 60,
    ]);
}

/**
 * @param  array<string, mixed>  $override
 */
function popOption(Service $service, array $override = []): ServiceOption
{
    return ServiceOption::factory()->for($service)->create([
        'name' => 'Perline',
        'extra_price' => '5.00',
        'extra_duration' => 15,
        ...$override,
    ]);
}

/**
 * A valid option form, with the given fields replaced.
 *
 * @param  array<string, mixed>  $override
 * @return array<string, mixed>
 */
function popForm(array $override = []): array
{
    return ['name' => 'Extension', 'extra_price' => '7,50', 'extra_duration' => 30, ...$override];
}

/**
 * Send one of the four routes.
 *
 * @param  array<string, mixed>  $data
 */
function popSend(?User $user, string $route, int|string $serviceId, int|string|null $optionId = null, array $data = []): TestResponse
{
    $test = $user === null ? test() : test()->actingAs($user);
    $parameters = $optionId === null ? ['service' => $serviceId] : ['service' => $serviceId, 'option' => $optionId];
    $url = route($route, $parameters);

    return match ($route) {
        'provider.services.options.index' => $test->get($url),
        'provider.services.options.store' => $test->post($url, $data === [] ? popForm() : $data),
        'provider.services.options.update' => $test->put($url, $data === [] ? popForm() : $data),
        default => $test->delete($url),
    };
}

/**
 * The columns that must not change when an action is refused.
 *
 * @return array<string, mixed>
 */
function popColumns(ServiceOption $option): array
{
    return $option->fresh()->only(['service_id', 'name', 'extra_price', 'extra_duration']);
}

function popBook(ServiceOption $option, string $status = 'pending'): Booking
{
    $service = $option->service()->firstOrFail();
    $booking = Booking::factory()->for($service->provider)->for($service)->create([
        'status' => $status,
        'starts_at' => CarbonImmutable::parse('2026-10-26 07:00', 'UTC'),
        'ends_at' => CarbonImmutable::parse('2026-10-26 08:15', 'UTC'),
    ]);
    $booking->options()->attach($option->id);

    return $booking;
}

/**
 * Run $run with a model listener on ServiceOption, then remove it in any case. ServiceOption has
 * no listeners of its own, so flushEventListeners() removes only this one.
 */
function popWhile(string $event, Closure $listener, Closure $run): mixed
{
    ServiceOption::{$event}($listener);

    try {
        return $run();
    } finally {
        ServiceOption::flushEventListeners();
    }
}

dataset('pop routes', [
    'index' => ['provider.services.options.index', false],
    'store' => ['provider.services.options.store', false],
    'update' => ['provider.services.options.update', true],
    'destroy' => ['provider.services.options.destroy', true],
]);

test('guests, unverified users and users without a provider are sent away', function (string $route, bool $withOption) {
    $service = popService(popProvider());
    $option = popOption($service);
    $optionId = $withOption ? $option->id : null;

    popSend(null, $route, $service->id, $optionId)->assertRedirect(route('login'));

    $unverified = User::factory()->unverified()->create();
    Provider::factory()->create(['user_id' => $unverified->id]);
    popSend($unverified, $route, $service->id, $optionId)->assertRedirect(route('verification.notice'));

    popSend(User::factory()->create(), $route, $service->id, $optionId)->assertRedirect(route('provider.setup'));

    expect(ServiceOption::query()->count())->toBe(1);
    expect(popColumns($option))->toBe(['service_id' => $service->id, 'name' => 'Perline', 'extra_price' => '5.00', 'extra_duration' => 15]);
})->with('pop routes');

test('the same 404 for any id that is not the user\'s, and nothing changes', function (string $route, bool $withOption) {
    $mine = popProvider();
    $myService = popService($mine);
    $myOtherService = popService($mine, 'Cornrows');
    $optionOfMyOtherService = popOption($myOtherService, ['name' => 'Di un altro servizio']);
    $theirService = popService(popProvider(name: 'Barberia Kofi'));
    $theirOption = popOption($theirService, ['name' => 'Di Kofi']);
    $watched = [$optionOfMyOtherService, $theirOption];
    $before = array_map(fn (ServiceOption $option) => popColumns($option), $watched);

    $cases = $withOption
        ? [
            'service of another provider' => [$theirService->id, $theirOption->id],
            'option of another service' => [$myService->id, $optionOfMyOtherService->id],
            'missing id' => [$myService->id, 999999],
            'not a number' => [$myService->id, 'abc'],
        ]
        : [
            // Without an option in the address, the "other service" case does not apply.
            'service of another provider' => [$theirService->id, null],
            'missing id' => [999999, null],
            'not a number' => ['abc', null],
        ];

    foreach ($cases as [$serviceId, $optionId]) {
        popSend($mine->user, $route, $serviceId, $optionId)->assertNotFound();
    }

    expect(array_map(fn (ServiceOption $option) => popColumns($option), $watched))->toBe($before);
    expect(ServiceOption::query()->count())->toBe(2);
})->with('pop routes');

test('the page lists the options of the service with exactly six keys, used and is_active', function () {
    $provider = popProvider();
    $service = popService($provider);
    $first = popOption($service, ['name' => 'Perline']);
    $second = popOption($service, ['name' => 'Extension', 'extra_price' => '0.00', 'extra_duration' => 0]);
    popBook($second);
    popOption(popService($provider, 'Cornrows'), ['name' => 'Opzione Di Un Altro Servizio']);

    $this->actingAs($provider->user)
        ->get(route('provider.services.options.index', $service->id))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('provider/services/options')
            ->has('service', 8)
            ->where('service.id', $service->id)
            ->where('options', [
                ['id' => $first->id, 'name' => 'Perline', 'extra_price' => '5.00', 'extra_duration' => 15, 'used' => false, 'is_active' => true],
                ['id' => $second->id, 'name' => 'Extension', 'extra_price' => '0.00', 'extra_duration' => 0, 'used' => true, 'is_active' => true],
            ]))
        ->assertDontSee('Opzione Di Un Altro Servizio');
});

test('the number of queries does not grow with options and bookings', function () {
    // A provider each: actingAs() keeps the same User object across requests of one user.
    $count = function (int $options, int $bookings) {
        $provider = popProvider(name: "Salone {$options} {$bookings}");
        $service = popService($provider);
        $created = collect(range(1, $options))->map(fn (int $i) => popOption($service, ['name' => "Opzione {$i}"]));

        for ($i = 0; $i < $bookings; $i++) {
            popBook($created->first());
        }

        $queries = 0;
        DB::listen(function () use (&$queries) {
            $queries++;
        });
        $this->actingAs($provider->user)->get(route('provider.services.options.index', $service->id))->assertOk();

        return $queries;
    };

    $base = $count(1, 0);

    expect($count(5, 0))->toBe($base);
    expect($count(1, 5))->toBe($base);
    expect($count(5, 5))->toBe($base);
});

test('a valid form adds the option to that service, the body cannot move it', function () {
    $provider = popProvider();
    $service = popService($provider);
    $otherService = popService($provider, 'Cornrows');

    popSend($provider->user, 'provider.services.options.store', $service->id, data: popForm([
        'service_id' => $otherService->id,
        'id' => 999,
        'is_active' => '0',
    ]))
        ->assertRedirect(route('provider.services.options.index', $service->id))
        ->assertSessionHasNoErrors()
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Opzione aggiunta.']);

    $option = ServiceOption::query()->sole();
    expect($option->service_id)->toBe($service->id);
    expect($option->id)->not->toBe(999);
    expect($option->only(['name', 'extra_price', 'extra_duration']))->toBe(['name' => 'Extension', 'extra_price' => '7.50', 'extra_duration' => 30]);
});

test('accepted extra prices', function (string $price, string $saved) {
    $provider = popProvider();
    $service = popService($provider);

    popSend($provider->user, 'provider.services.options.store', $service->id, data: popForm(['extra_price' => $price]))->assertSessionHasNoErrors();

    expect(ServiceOption::query()->sole()->extra_price)->toBe($saved);
})->with([
    ['0', '0.00'],
    ['5', '5.00'],
    ['7,50', '7.50'],
    ['7.5', '7.50'],
    ['0,01', '0.01'],
    ['999,99', '999.99'],
    ['007', '7.00'],
]);

test('refused extra prices, with one message', function (string $price) {
    $provider = popProvider();
    $service = popService($provider);

    popSend($provider->user, 'provider.services.options.store', $service->id, data: popForm(['extra_price' => $price]))
        ->assertSessionHasErrors(['extra_price' => POP_PRICE_INVALID]);

    expect(session('errors')->get('extra_price'))->toHaveCount(1);
    expect(ServiceOption::query()->count())->toBe(0);
})->with(['abc', '1e2', '1000', '-1', '1.000,50', '5,999', '5 €']);

test('an empty extra price asks to write 0', function () {
    $provider = popProvider();

    popSend($provider->user, 'provider.services.options.store', popService($provider)->id, data: popForm(['extra_price' => '']))
        ->assertSessionHasErrors(['extra_price' => 'Scrivi il prezzo extra: se non costa niente, scrivi 0.']);
});

test('accepted and refused extra minutes', function (mixed $minutes, ?string $error) {
    $provider = popProvider();
    $service = popService($provider);

    $response = popSend($provider->user, 'provider.services.options.store', $service->id, data: popForm(['extra_duration' => $minutes]));

    if ($error === null) {
        $response->assertSessionHasNoErrors();
        expect(ServiceOption::query()->sole()->extra_duration)->toBe((int) $minutes);
    } else {
        $response->assertSessionHasErrors(['extra_duration' => $error]);
        expect(ServiceOption::query()->count())->toBe(0);
    }
})->with([
    [0, null],
    [5, null],
    [240, null],
    [7, POP_DURATION_INVALID],
    [245, POP_DURATION_INVALID],
    [-5, POP_DURATION_INVALID],
    ['1.5', POP_DURATION_INVALID],
    ['abc', POP_DURATION_INVALID],
    ['', 'Scrivi i minuti extra: se non allungano il servizio, scrivi 0.'],
]);

test('names: required, at most 150, unique in the service only', function () {
    $provider = popProvider();
    $service = popService($provider);
    $otherService = popService($provider, 'Cornrows');
    $store = fn (int $serviceId, string $name) => popSend($provider->user, 'provider.services.options.store', $serviceId, data: popForm(['name' => $name]));

    $store($service->id, '')->assertSessionHasErrors(['name' => "Scrivi il nome dell'opzione."]);
    $store($service->id, '    ')->assertSessionHasErrors(['name' => "Scrivi il nome dell'opzione."]);
    $store($service->id, str_repeat('a', 151))->assertSessionHasErrors(['name' => 'Il nome può avere al massimo 150 caratteri.']);
    expect(ServiceOption::query()->count())->toBe(0);

    $store($service->id, str_repeat('a', 150))->assertSessionHasNoErrors();
    $store($service->id, 'Perline')->assertSessionHasNoErrors();
    $store($service->id, '  Perline  ')->assertSessionHasErrors(['name' => "Hai già un'opzione con questo nome per questo servizio."]);
    $store($otherService->id, 'Perline')->assertSessionHasNoErrors();
    expect(ServiceOption::query()->count())->toBe(3);

    // In the edit, the same option may keep its name.
    $perline = ServiceOption::query()->where('service_id', $service->id)->where('name', 'Perline')->sole();
    popSend($provider->user, 'provider.services.options.update', $service->id, $perline->id, popForm(['name' => 'Perline']))->assertSessionHasNoErrors();
});

test('the 20th option is created, the 21st is refused', function () {
    $provider = popProvider();
    $service = popService($provider);
    ServiceOption::factory()->count(19)->for($service)->sequence(fn ($sequence) => ['name' => "Opzione {$sequence->index}"])->create();

    popSend($provider->user, 'provider.services.options.store', $service->id, data: popForm(['name' => 'La ventesima']))->assertSessionHasNoErrors();
    popSend($provider->user, 'provider.services.options.store', $service->id, data: popForm(['name' => 'La ventunesima']))
        ->assertSessionHasErrors(['name' => 'Un servizio può avere al massimo 20 opzioni.']);

    expect($service->options()->count())->toBe(20);
});

test('documents a limit: an insert in the same transaction does not go through the lock', function () {
    // NOT a proof of the protection. The limit is checked in the controller after lockForUpdate on
    // the service, which makes two REQUESTS wait for each other on MySQL. This listener inserts an
    // option with the query builder inside the same transaction and connection, so it never meets
    // the lock; SQLite, used by the tests, ignores lockForUpdate anyway. The service ends with 21.
    $provider = popProvider();
    $service = popService($provider);
    ServiceOption::factory()->count(19)->for($service)->sequence(fn ($sequence) => ['name' => "Opzione {$sequence->index}"])->create();
    $inserted = false;

    popWhile('creating', function () use ($service, &$inserted) {
        if (! $inserted) {
            $inserted = true;
            DB::table('service_options')->insert([
                'service_id' => $service->id,
                'name' => 'Inserita nel frattempo',
                'extra_price' => 0,
                'extra_duration' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }, fn () => popSend($provider->user, 'provider.services.options.store', $service->id, data: popForm(['name' => 'La ventesima']))->assertSessionHasNoErrors());

    expect($inserted)->toBeTrue();
    expect($service->options()->count())->toBe(21);
});

test('HTML in the name is saved as written', function () {
    $provider = popProvider();

    popSend($provider->user, 'provider.services.options.store', popService($provider)->id, data: popForm(['name' => '<script>x</script>']))->assertSessionHasNoErrors();

    expect(ServiceOption::query()->sole()->name)->toBe('<script>x</script>');
});

test('a valid edit changes the three fields, the body cannot move it', function () {
    $provider = popProvider();
    $service = popService($provider);
    $otherService = popService($provider, 'Cornrows');
    $option = popOption($service);

    popSend($provider->user, 'provider.services.options.update', $service->id, $option->id, popForm([
        'service_id' => $otherService->id,
        'id' => 999,
        'is_active' => '0',
    ]))
        ->assertRedirect(route('provider.services.options.index', $service->id))
        ->assertSessionHasNoErrors()
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Opzione aggiornata.']);

    expect(popColumns($option))->toBe(['service_id' => $service->id, 'name' => 'Extension', 'extra_price' => '7.50', 'extra_duration' => 30]);
});

test('editing a booked option does not change the existing bookings', function () {
    $provider = popProvider();
    $service = popService($provider);
    $option = popOption($service);
    $booking = popBook($option);

    popSend($provider->user, 'provider.services.options.update', $service->id, $option->id, popForm(['extra_duration' => 120]))->assertSessionHasNoErrors();

    $fresh = $booking->fresh();
    expect($fresh->starts_at->toIso8601String())->toBe('2026-10-26T07:00:00+00:00');
    expect($fresh->ends_at->toIso8601String())->toBe('2026-10-26T08:15:00+00:00');
    expect($fresh->options()->pluck('service_options.id')->all())->toBe([$option->id]);
});

test('an option never booked is deleted, its service does not change', function () {
    $provider = popProvider();
    $service = popService($provider);
    $option = popOption($service);
    $serviceBefore = $service->fresh()->only(['is_active', 'name', 'price']);

    popSend($provider->user, 'provider.services.options.destroy', $service->id, $option->id)
        ->assertRedirect(route('provider.services.options.index', $service->id))
        ->assertSessionHasNoErrors()
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Opzione eliminata.']);

    expect(ServiceOption::query()->whereKey($option->id)->exists())->toBeFalse();
    expect($service->fresh()->only(['is_active', 'name', 'price']))->toBe($serviceBefore);
});

test('an option chosen in a booking, even only a cancelled one, is not deleted', function (string $status) {
    $provider = popProvider();
    $service = popService($provider);
    $option = popOption($service);
    popBook($option, $status);
    $before = popColumns($option);

    popSend($provider->user, 'provider.services.options.destroy', $service->id, $option->id)
        ->assertRedirect(route('provider.services.options.index', $service->id))
        ->assertSessionHasErrors(['option' => POP_CANNOT_DELETE])
        ->assertInertiaFlashMissing('toast');

    expect(popColumns($option))->toBe($before);
})->with(['pending', 'confirmed', 'cancelled']);

test('a booking choosing the option just before the delete gets the message, not a 500', function () {
    // The counter-proofs (without blockedBy, and without the catch) are in ManageOptionTest.
    $provider = popProvider();
    $service = popService($provider);
    $option = popOption($service);
    $linked = false;

    popWhile('deleting', function (ServiceOption $deleting) use (&$linked) {
        if (! $linked) {
            $linked = true;
            popBook($deleting);
        }
    }, fn () => popSend($provider->user, 'provider.services.options.destroy', $service->id, $option->id)
        ->assertRedirect(route('provider.services.options.index', $service->id))
        ->assertSessionHasErrors(['option' => POP_CANNOT_DELETE]));

    expect($linked)->toBeTrue();
    expect(ServiceOption::query()->whereKey($option->id)->exists())->toBeTrue();
});

test('the listeners of the tests above are not registered any more', function () {
    expect(Event::hasListeners('eloquent.creating: '.ServiceOption::class))->toBeFalse();
    expect(Event::hasListeners('eloquent.deleting: '.ServiceOption::class))->toBeFalse();
});

test('an option added from the form shows on the public page and can be booked', function () {
    $provider = popProvider();
    $service = popService($provider);
    popSend($provider->user, 'provider.services.options.store', $service->id, data: popForm(['name' => 'Extension', 'extra_price' => '7,50', 'extra_duration' => 15]))->assertSessionHasNoErrors();
    $option = ServiceOption::query()->sole();

    // As a customer: a guest.
    app('auth')->forgetGuards();
    $groups = $this->get(route('providers.show', $provider->slug))->assertOk()->viewData('page')['props']['provider']['service_groups'];
    $shown = collect($groups)->flatMap(fn (array $group) => $group['services'])->firstWhere('id', $service->id)['options'];
    expect($shown)->toBe([['id' => $option->id, 'name' => 'Extension', 'extra_price' => '7.50', 'extra_duration' => 15]]);

    $this->post(route('providers.bookings.store', $provider->slug), [
        'service' => $service->id,
        'options' => [$option->id],
        'starts_at' => '2026-10-19T07:00:00Z',
        'customer_name' => 'Awa Diop',
        'customer_phone' => '+39 333 123 4567',
    ])->assertSessionHasNoErrors();

    // 60 minutes of the service (duration_max) plus 15 of the option.
    $booking = Booking::query()->sole();
    expect($booking->ends_at->toIso8601String())->toBe('2026-10-19T08:15:00+00:00');
    expect($booking->options()->pluck('service_options.id')->all())->toBe([$option->id]);
});

test('the 21st new option in a minute of the same user is refused', function () {
    $provider = popProvider();
    // Two services, so that the 20 options per service never get in the way of the request limit.
    $first = popService($provider);
    $second = popService($provider, 'Cornrows');

    for ($i = 1; $i <= 20; $i++) {
        $service = $i <= 10 ? $first : $second;
        popSend($provider->user, 'provider.services.options.store', $service->id, data: popForm(['name' => "Opzione {$i}"]))->assertSessionHasNoErrors();
    }

    popSend($provider->user, 'provider.services.options.store', $second->id, data: popForm(['name' => 'Opzione 21']))
        ->assertSessionHasErrors(['services' => 'Hai fatto troppe richieste in poco tempo. Riprova tra qualche minuto.']);
    expect(ServiceOption::query()->count())->toBe(20);

    $other = popProvider(name: 'Barberia Kofi');
    popSend($other->user, 'provider.services.options.store', popService($other)->id)->assertSessionHasNoErrors();
});

test('the 31st edit or delete in a minute of the same user is refused', function () {
    $provider = popProvider();
    $service = popService($provider);
    $option = popOption($service);

    for ($i = 1; $i <= 30; $i++) {
        popSend($provider->user, 'provider.services.options.update', $service->id, $option->id, popForm(['name' => "Nome {$i}"]))->assertSessionHasNoErrors();
    }

    popSend($provider->user, 'provider.services.options.destroy', $service->id, $option->id)
        ->assertSessionHasErrors(['services' => 'Hai fatto troppe richieste in poco tempo. Riprova tra qualche minuto.']);
    expect(ServiceOption::query()->whereKey($option->id)->exists())->toBeTrue();

    $other = popProvider(name: 'Barberia Kofi');
    $theirs = popOption(popService($other));
    popSend($other->user, 'provider.services.options.update', $theirs->service_id, $theirs->id)->assertSessionHasNoErrors();
});

test('the Italian texts are exactly the expected ones', function () {
    $texts = [
        'provider.options.validation.name_required' => "Scrivi il nome dell'opzione.",
        'provider.options.validation.name_max' => 'Il nome può avere al massimo :max caratteri.',
        'provider.options.validation.name_taken' => "Hai già un'opzione con questo nome per questo servizio.",
        'provider.options.validation.price_required' => 'Scrivi il prezzo extra: se non costa niente, scrivi 0.',
        'provider.options.validation.price_invalid' => POP_PRICE_INVALID,
        'provider.options.validation.duration_required' => 'Scrivi i minuti extra: se non allungano il servizio, scrivi 0.',
        'provider.options.validation.duration_invalid' => POP_DURATION_INVALID,
        'provider.options.validation.limit_reached' => 'Un servizio può avere al massimo :max opzioni.',
        'provider.options.created' => 'Opzione aggiunta.',
        'provider.options.updated' => 'Opzione aggiornata.',
        'provider.options.deleted' => 'Opzione eliminata.',
        'provider.options.cannot_delete' => POP_CANNOT_DELETE,
        'provider.options.title' => 'Opzioni ed extra',
        'provider.options.description' => 'Varianti e aggiunte che il cliente può scegliere con questo servizio.',
        'provider.options.empty' => 'Questo servizio non ha ancora opzioni.',
        'provider.options.add_title' => "Aggiungi un'opzione",
        'provider.options.form.name' => "Nome dell'opzione",
        'provider.options.form.price' => 'Prezzo extra in euro',
        'provider.options.form.price_help' => 'Per esempio 5 oppure 7,50. Scrivi 0 se non costa niente.',
        'provider.options.form.duration' => 'Minuti extra',
        'provider.options.form.duration_help' => 'Quanto allunga il servizio. Il calendario blocca anche questi minuti.',
        'provider.options.form.submit' => "Aggiungi l'opzione",
        'provider.options.form.save' => 'Salva le modifiche',
        'provider.options.edit_button' => 'Modifica',
        'provider.options.edit_title' => "Modifica l'opzione",
        'provider.options.delete_button' => 'Elimina',
        'provider.options.delete_confirm_title' => "Vuoi eliminare l'opzione?",
        'provider.options.delete_confirm_description' => "L'opzione verrà cancellata. L'operazione non si può annullare.",
        'provider.options.delete_confirm_yes' => 'Sì, eliminala',
        'provider.options.delete_confirm_no' => 'No, tienila',
        'provider.options.delete_blocked' => 'Questa opzione è già stata scelta in alcune prenotazioni e non si può eliminare: puoi nasconderla.',
        'provider.options.link_title' => 'Opzioni ed extra',
        'provider.options.link_button' => 'Gestisci le opzioni',
    ];

    foreach ($texts as $key => $text) {
        expect(__($key))->toBe($text);
    }
});
