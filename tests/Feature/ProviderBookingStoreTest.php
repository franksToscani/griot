<?php

use App\Booking\BookingDuration;
use App\Enums\BookingStatus;
use App\Enums\Weekday;
use App\Models\Availability;
use App\Models\Booking;
use App\Models\Provider;
use App\Models\Service;
use App\Models\ServiceOption;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;

// "Now" is Monday 19 October 2026, 07:00 UTC (09:00 in Italy, summer time UTC+2). The providers
// work today 09:00-13:00 local, 07:00-11:00 UTC. The 60-day window ends on Friday 18 December:
// Monday 14 December is inside it, Monday 21 December outside.
const PBS_TODAY = '2026-10-19';

const PBS_THROTTLE = 'Hai fatto troppe richieste in poco tempo. Riprova tra qualche minuto.';

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse(PBS_TODAY.' 07:00', 'UTC'));
    app()->setLocale('it');
});

/**
 * A provider working on Mondays from 09:00 to 13:00 local.
 */
function pbsProvider(string $name = 'Salone Awa', bool $published = true): Provider
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
function pbsService(Provider $provider, int $minutes = 60, array $attributes = []): Service
{
    return Service::factory()->for($provider)->create(['duration_min' => 30, 'duration_max' => $minutes, ...$attributes]);
}

/**
 * The form of a valid booking: today 09:00 UTC (11:00 local), bookable by customers too.
 *
 * @param  array<string, mixed>  $override
 * @return array<string, mixed>
 */
function pbsData(Service $service, array $override = []): array
{
    return [
        'service' => $service->id,
        'starts_at' => PBS_TODAY.'T09:00:00Z',
        'customer_name' => 'Awa Diop',
        'customer_phone' => '333 123 4567',
        ...$override,
    ];
}

/**
 * @param  array<string, mixed>  $data
 */
function pbsPost(?User $user, array $data): TestResponse
{
    $test = $user === null ? test() : test()->actingAs($user);

    return $test->post(route('provider.bookings.store'), $data);
}

/**
 * @param  array<string, mixed>  $query
 */
function pbsSlots(?User $user, array $query): TestResponse
{
    $test = $user === null ? test() : test()->actingAs($user);

    return $test->getJson(route('provider.bookings.slots', $query));
}

/**
 * The local times of the provider's own slots, in a 200 response.
 *
 * @return list<string>
 */
function pbsTimes(User $user, Service $service, string $date = PBS_TODAY): array
{
    return array_column(pbsSlots($user, ['service' => $service->id, 'date' => $date])->assertOk()->json('slots'), 'time');
}

/**
 * The local times of the public slots, for the same service and day.
 *
 * @return list<string>
 */
function pbsPublicTimes(Provider $provider, Service $service, string $date = PBS_TODAY): array
{
    $url = route('providers.slots', ['publishedProvider' => $provider->slug, 'service' => $service->id, 'date' => $date]);

    return array_column(test()->getJson($url)->assertOk()->json('slots'), 'time');
}

/**
 * A booking of the provider today, between two UTC times.
 */
function pbsExisting(Provider $provider, string $start, string $end, BookingStatus $status): Booking
{
    return Booking::factory()->for($provider)->for(pbsService($provider))->create([
        'starts_at' => CarbonImmutable::parse(PBS_TODAY." {$start}", 'UTC'),
        'ends_at' => CarbonImmutable::parse(PBS_TODAY." {$end}", 'UTC'),
        'status' => $status,
    ]);
}

// ---------------------------------------------------------------------------------------------
// Creation
// ---------------------------------------------------------------------------------------------

test('the provider writes a booking: confirmed, created by the provider, back to the list with a toast', function () {
    $provider = pbsProvider();
    $service = pbsService($provider, 60);
    $option = ServiceOption::factory()->for($service)->create(['extra_duration' => 30]);

    pbsPost($provider->user, pbsData($service, [
        'options' => [$option->id],
        'customer_email' => 'awa@example.com',
        'notes' => 'Capelli lunghi',
    ]))
        ->assertRedirect(route('provider.bookings.index'))
        ->assertSessionHasNoErrors()
        ->assertInertiaFlash('toast', ['type' => 'success', 'message' => 'Prenotazione aggiunta e già confermata.']);

    $booking = Booking::query()->sole();
    // The minutes come from BookingDuration, the same rule as the customer's form: 60 + 30.
    $minutes = app(BookingDuration::class)->minutesFor($service, [$option->id]);
    expect($minutes)->toBe(90);
    expect($booking->provider_id)->toBe($provider->id)
        ->and($booking->service_id)->toBe($service->id)
        ->and($booking->status)->toBe(BookingStatus::Confirmed)
        ->and($booking->created_by_provider)->toBeTrue()
        ->and($booking->starts_at->toIso8601ZuluString())->toBe('2026-10-19T09:00:00Z')
        ->and($booking->ends_at->toIso8601ZuluString())->toBe('2026-10-19T10:30:00Z')
        ->and($booking->customer_name)->toBe('Awa Diop')
        ->and($booking->customer_phone)->toBe('+393331234567')
        ->and($booking->customer_email)->toBe('awa@example.com')
        ->and($booking->notes)->toBe('Capelli lunghi')
        ->and($booking->public_token)->toMatch('/^[A-Za-z0-9]{48}$/')
        ->and($booking->cancelled_by)->toBeNull()
        ->and($booking->cancellation_reason)->toBeNull();
    expect($booking->options()->pluck('service_options.id')->all())->toBe([$option->id]);
});

test('name and phone are the only required fields: empty email and notes are saved as null', function () {
    $provider = pbsProvider();
    $service = pbsService($provider);

    pbsPost($provider->user, pbsData($service, ['customer_email' => '', 'notes' => '   ']))
        ->assertSessionHasNoErrors();

    $booking = Booking::query()->sole();
    expect($booking->customer_email)->toBeNull()->and($booking->notes)->toBeNull();

    pbsPost($provider->user, pbsData($service, ['customer_name' => '', 'customer_phone' => '', 'starts_at' => '']))
        ->assertSessionHasErrors([
            // The provider's own message, about the customer (phase B of issue #49).
            'customer_name' => 'Scrivi il nome del cliente.',
            'customer_phone' => 'Scrivi un numero di telefono.',
            'starts_at' => 'Scegli un orario.',
        ]);
    expect(Booking::query()->count())->toBe(1);
});

test('the phone is normalized as on the customer form', function (string $typed, string $saved) {
    $provider = pbsProvider();
    $service = pbsService($provider);

    pbsPost($provider->user, pbsData($service, ['customer_phone' => $typed]))->assertSessionHasNoErrors();

    expect(Booking::query()->sole()->customer_phone)->toBe($saved);
})->with([
    'Italian mobile without prefix' => ['333 123 4567', '+393331234567'],
    'Cameroonian mobile without prefix' => ['6 77 12 34 56', '+237677123456'],
    'already international' => ['+33 6 12 34 56 78', '+33612345678'],
]);

test('a phone that cannot be read is refused with the customer form message', function () {
    $provider = pbsProvider();
    $service = pbsService($provider);

    pbsPost($provider->user, pbsData($service, ['customer_phone' => '12345']))
        ->assertSessionHasErrors(['customer_phone' => __('booking.phone.invalid')]);
    expect(Booking::query()->count())->toBe(0);
});

test('the body cannot choose provider, status, author, end or token', function () {
    $provider = pbsProvider();
    $other = pbsProvider('Barberia Kofi');
    $service = pbsService($provider, 60);

    pbsPost($provider->user, pbsData($service, [
        'provider_id' => $other->id,
        'status' => BookingStatus::Cancelled->value,
        'created_by_provider' => '0',
        'ends_at' => '2026-10-19T20:00:00Z',
        'public_token' => str_repeat('a', 48),
        'cancelled_by' => 'customer',
    ]))->assertSessionHasNoErrors();

    $booking = Booking::query()->sole();
    expect($booking->provider_id)->toBe($provider->id)
        ->and($booking->status)->toBe(BookingStatus::Confirmed)
        ->and($booking->created_by_provider)->toBeTrue()
        ->and($booking->ends_at->toIso8601ZuluString())->toBe('2026-10-19T10:00:00Z')
        ->and($booking->public_token)->not->toBe(str_repeat('a', 48))
        ->and($booking->cancelled_by)->toBeNull();
});

test('no email or notification leaves, neither to the provider nor to the customer', function () {
    Notification::fake();
    Queue::fake();
    $provider = pbsProvider();
    $service = pbsService($provider);

    pbsPost($provider->user, pbsData($service, ['customer_email' => 'awa@example.com']))->assertSessionHasNoErrors();

    expect(Booking::query()->count())->toBe(1);
    Notification::assertNothingSent();
    Queue::assertNothingPushed();
});

// ---------------------------------------------------------------------------------------------
// The same rules as the customer, except the minimum notice
// ---------------------------------------------------------------------------------------------

test('a start that overlaps a pending or a confirmed booking is refused; a cancelled one frees it', function (BookingStatus $status, bool $accepted) {
    $provider = pbsProvider();
    $service = pbsService($provider, 60);
    pbsExisting($provider, '09:30', '10:30', $status);

    $response = pbsPost($provider->user, pbsData($service));

    if ($accepted) {
        $response->assertSessionHasNoErrors();
        expect(Booking::query()->where('created_by_provider', true)->count())->toBe(1);
    } else {
        $response->assertSessionHasErrors(['starts_at' => 'Questo orario non è disponibile. Scegli un altro orario.']);
        expect(Booking::query()->where('created_by_provider', true)->count())->toBe(0);
    }
})->with([
    'pending' => [BookingStatus::Pending, false],
    'confirmed' => [BookingStatus::Confirmed, false],
    'cancelled' => [BookingStatus::Cancelled, true],
]);

test('a start in 30 minutes is accepted from the provider but refused on the public form', function () {
    $provider = pbsProvider();
    $service = pbsService($provider);
    $start = PBS_TODAY.'T07:30:00Z';

    // The public form, 120 minutes of notice.
    $this->post(route('providers.bookings.store', $provider->slug), pbsData($service, ['starts_at' => $start]))
        ->assertSessionHasErrors('starts_at');
    expect(Booking::query()->count())->toBe(0);

    pbsPost($provider->user, pbsData($service, ['starts_at' => $start]))->assertSessionHasNoErrors();
    expect(Booking::query()->sole()->starts_at->toIso8601ZuluString())->toBe($start);
});

test('a start in the past is refused on both forms, even by a few minutes', function () {
    $provider = pbsProvider();
    $service = pbsService($provider);
    // 07:00 UTC is the first slot of the day; at 07:10 it has already started.
    $this->travelTo(CarbonImmutable::parse(PBS_TODAY.' 07:10', 'UTC'));
    $start = PBS_TODAY.'T07:00:00Z';

    pbsPost($provider->user, pbsData($service, ['starts_at' => $start]))->assertSessionHasErrors('starts_at');
    $this->post(route('providers.bookings.store', $provider->slug), pbsData($service, ['starts_at' => $start]))
        ->assertSessionHasErrors('starts_at');
    expect(Booking::query()->count())->toBe(0);
});

test('a start exactly now is still bookable by the provider', function () {
    $provider = pbsProvider();
    $service = pbsService($provider);

    pbsPost($provider->user, pbsData($service, ['starts_at' => PBS_TODAY.'T07:00:00Z']))->assertSessionHasNoErrors();
    expect(Booking::query()->count())->toBe(1);
});

test('the 60-day window applies to the provider too', function () {
    $provider = pbsProvider();
    $service = pbsService($provider);

    // Winter time: 09:00 local is 08:00 UTC.
    pbsPost($provider->user, pbsData($service, ['starts_at' => '2026-12-21T08:00:00Z']))
        ->assertSessionHasErrors(['starts_at' => 'Questo orario non è disponibile. Scegli un altro orario.']);
    expect(Booking::query()->count())->toBe(0);

    pbsPost($provider->user, pbsData($service, ['starts_at' => '2026-12-14T08:00:00Z']))->assertSessionHasNoErrors();
    expect(Booking::query()->count())->toBe(1);
});

test('outside the time windows, off the grid or on an inactive service the start is refused', function (string $start, array $serviceAttributes) {
    $provider = pbsProvider();
    $service = pbsService($provider, 60, $serviceAttributes);

    pbsPost($provider->user, pbsData($service, ['starts_at' => $start]))
        ->assertSessionHasErrors(['starts_at' => 'Questo orario non è disponibile. Scegli un altro orario.']);
    expect(Booking::query()->count())->toBe(0);
})->with([
    // 14:00 local, after the window.
    'outside the window' => [PBS_TODAY.'T12:00:00Z', []],
    // 12:30 local would end at 13:30, after the window: the last start is 12:00.
    'not fitting before the end' => [PBS_TODAY.'T10:30:00Z', []],
    'off the 30-minute grid' => [PBS_TODAY.'T09:15:00Z', []],
    'inactive service' => [PBS_TODAY.'T09:00:00Z', ['is_active' => false]],
]);

test('an unpublished provider cannot write bookings, as customers cannot book it', function () {
    $provider = pbsProvider(published: false);
    $service = pbsService($provider);

    pbsPost($provider->user, pbsData($service))->assertSessionHasErrors('starts_at');
    expect(Booking::query()->count())->toBe(0);
    expect(pbsTimes($provider->user, $service))->toBe([]);
});

test('a hidden option or an option of another service is refused', function () {
    $provider = pbsProvider();
    $service = pbsService($provider);
    $hidden = ServiceOption::factory()->for($service)->create(['is_active' => false]);
    $elsewhere = ServiceOption::factory()->for(pbsService($provider))->create();

    foreach ([$hidden->id, $elsewhere->id] as $optionId) {
        pbsPost($provider->user, pbsData($service, ['options' => [$optionId]]))
            ->assertSessionHasErrors(['options' => 'Alcune opzioni scelte non appartengono a questo servizio.']);
    }
    expect(Booking::query()->count())->toBe(0);
});

test('a service of another provider and a missing one get the same message as the public route', function () {
    $provider = pbsProvider();
    $theirs = pbsService(pbsProvider('Barberia Kofi'));

    foreach ([$theirs->id, 999999] as $serviceId) {
        pbsPost($provider->user, ['service' => $serviceId] + pbsData($theirs))
            ->assertSessionHasErrors(['service' => 'Il servizio scelto non è disponibile per questo professionista.']);
    }
    expect(Booking::query()->count())->toBe(0);
});

test('the number of queries of a new booking does not grow with the options', function () {
    // A provider each: actingAs() keeps the same User object across requests of one user.
    $count = function (int $options) {
        $provider = pbsProvider("Salone {$options}");
        $service = pbsService($provider);
        $ids = ServiceOption::factory()->count($options)->for($service)->create(['extra_duration' => 0])->modelKeys();

        $queries = 0;
        DB::listen(function () use (&$queries) {
            $queries++;
        });
        pbsPost($provider->user, pbsData($service, ['options' => $ids]))->assertSessionHasNoErrors();

        return $queries;
    };

    expect($count(5))->toBe($count(1));
});

// ---------------------------------------------------------------------------------------------
// Access
// ---------------------------------------------------------------------------------------------

test('guests, unverified users and users without a provider are sent away', function () {
    $provider = pbsProvider();
    $service = pbsService($provider);
    $query = ['service' => $service->id, 'date' => PBS_TODAY];

    pbsPost(null, pbsData($service))->assertRedirect(route('login'));
    $this->get(route('provider.bookings.slots', $query))->assertRedirect(route('login'));
    pbsSlots(null, $query)->assertUnauthorized();

    $unverified = User::factory()->unverified()->create();
    Provider::factory()->create(['user_id' => $unverified->id]);
    pbsPost($unverified, pbsData($service))->assertRedirect(route('verification.notice'));
    $this->actingAs($unverified)->get(route('provider.bookings.slots', $query))->assertRedirect(route('verification.notice'));

    $withoutProvider = User::factory()->create();
    pbsPost($withoutProvider, pbsData($service))->assertRedirect(route('provider.setup'));
    $this->actingAs($withoutProvider)->get(route('provider.bookings.slots', $query))->assertRedirect(route('provider.setup'));

    expect(Booking::query()->count())->toBe(0);
});

test('the routes accept only their method, and the names are not the public ones', function () {
    $provider = pbsProvider();

    // GET on provider/bookings is the list: the store has no GET of its own.
    $this->actingAs($provider->user)->post('/provider/bookings/slots')->assertMethodNotAllowed();
    $this->actingAs($provider->user)->put('/provider/bookings')->assertMethodNotAllowed();
    expect(route('provider.bookings.store'))->toBe(url('/provider/bookings'))
        ->and(route('provider.bookings.slots'))->toBe(url('/provider/bookings/slots'))
        ->and(route('providers.bookings.store', 'x'))->toBe(url('/providers/x/bookings'));
});

// ---------------------------------------------------------------------------------------------
// Free slots of the provider
// ---------------------------------------------------------------------------------------------

test('the provider slots have the public shape, without the minimum notice', function () {
    $provider = pbsProvider();
    $service = pbsService($provider, 60);

    pbsSlots($provider->user, ['service' => $service->id, 'date' => PBS_TODAY])->assertOk()->assertExactJson([
        'date' => '2026-10-19',
        'timezone' => 'Europe/Rome',
        'slots' => [
            ['start' => '2026-10-19T07:00:00Z', 'time' => '09:00'],
            ['start' => '2026-10-19T07:30:00Z', 'time' => '09:30'],
            ['start' => '2026-10-19T08:00:00Z', 'time' => '10:00'],
            ['start' => '2026-10-19T08:30:00Z', 'time' => '10:30'],
            ['start' => '2026-10-19T09:00:00Z', 'time' => '11:00'],
            ['start' => '2026-10-19T09:30:00Z', 'time' => '11:30'],
            ['start' => '2026-10-19T10:00:00Z', 'time' => '12:00'],
        ],
    ]);
    // The customers see the same day from 11:00 local, two hours from now.
    expect(pbsPublicTimes($provider, $service))->toBe(['11:00', '11:30', '12:00']);
});

test('the provider slots leave out the busy times and the past ones', function () {
    $provider = pbsProvider();
    $service = pbsService($provider, 60);
    pbsExisting($provider, '08:00', '09:00', BookingStatus::Confirmed);
    pbsExisting($provider, '09:00', '10:00', BookingStatus::Cancelled);
    $this->travelTo(CarbonImmutable::parse(PBS_TODAY.' 07:10', 'UTC'));

    // 07:00 UTC has started; 07:30 and 08:00-08:30 overlap the confirmed booking.
    expect(pbsTimes($provider->user, $service))->toBe(['11:00', '11:30', '12:00']);
});

test('the provider slots are always the user\'s own: another provider\'s service is refused', function () {
    $provider = pbsProvider();
    $other = pbsProvider('Barberia Kofi');
    $theirs = pbsService($other);
    pbsService($provider);

    foreach ([$theirs->id, 999999] as $serviceId) {
        pbsSlots($provider->user, ['service' => $serviceId, 'date' => PBS_TODAY])
            ->assertUnprocessable()
            ->assertExactJson([
                'message' => 'Il servizio scelto non è disponibile per questo professionista.',
                'errors' => ['service' => ['Il servizio scelto non è disponibile per questo professionista.']],
            ]);
    }

    // A slug or an id in the query is ignored: the other provider's service stays refused.
    pbsSlots($provider->user, ['service' => $theirs->id, 'date' => PBS_TODAY, 'provider' => $other->slug, 'provider_id' => $other->id])
        ->assertUnprocessable();
});

test('the provider slots refuse a day outside the window, with the public message', function (string $date) {
    $provider = pbsProvider();
    $service = pbsService($provider);

    pbsSlots($provider->user, ['service' => $service->id, 'date' => $date])
        ->assertUnprocessable()
        ->assertJsonPath('errors.date.0', 'Scegli un giorno da oggi fino a 60 giorni da adesso.');
})->with(['yesterday' => '2026-10-18', 'after the window' => '2026-12-21']);

test('the provider slots refuse a hidden option and a wrong format like the public route', function () {
    $provider = pbsProvider();
    $service = pbsService($provider);
    $hidden = ServiceOption::factory()->for($service)->create(['is_active' => false]);

    pbsSlots($provider->user, ['service' => $service->id, 'date' => PBS_TODAY, 'options' => [$hidden->id]])
        ->assertUnprocessable()
        ->assertJsonPath('errors.options.0', 'Alcune opzioni scelte non appartengono a questo servizio.');
    pbsSlots($provider->user, ['service' => $service->id, 'date' => '19/10/2026'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('date');
});

// ---------------------------------------------------------------------------------------------
// Rate limit
// ---------------------------------------------------------------------------------------------

test('over the limit, the booking goes back to the list with the error and the slots get a 429', function () {
    config()->set('booking.rate_limit_provider_bookings_per_minute', 2);
    $provider = pbsProvider();
    $service = pbsService($provider);
    $query = ['service' => $service->id, 'date' => PBS_TODAY];

    // Slots and bookings count together.
    pbsSlots($provider->user, $query)->assertOk();
    pbsPost($provider->user, pbsData($service))->assertSessionHasNoErrors();

    pbsPost($provider->user, pbsData($service, ['starts_at' => PBS_TODAY.'T10:00:00Z']))
        ->assertRedirect(route('provider.bookings.index'))
        ->assertSessionHasErrors(['booking' => PBS_THROTTLE]);
    pbsSlots($provider->user, $query)->assertTooManyRequests()->assertHeader('Retry-After');
    expect(Booking::query()->count())->toBe(1);

    // Another provider has a counter of its own.
    $other = pbsProvider('Barberia Kofi');
    pbsSlots($other->user, ['service' => pbsService($other)->id, 'date' => PBS_TODAY])->assertOk();
});

test('the counter is not shared with provider-services-manage, in either direction', function () {
    config()->set('booking.rate_limit_provider_bookings_per_minute', 1);
    config()->set('booking.rate_limit_services_manage_per_minute', 1);
    $provider = pbsProvider();
    $service = pbsService($provider);
    $pending = pbsExisting($provider, '10:00', '11:00', BookingStatus::Pending);

    // One new booking uses up provider-bookings...
    pbsPost($provider->user, pbsData($service))->assertSessionHasNoErrors();
    pbsPost($provider->user, pbsData($service))->assertSessionHasErrors(['booking' => PBS_THROTTLE]);

    // ...and the answers to the bookings still work: one confirmation uses up provider-services-manage.
    $this->actingAs($provider->user)->post(route('provider.bookings.confirm', $pending->id))->assertSessionHasNoErrors();
    expect($pending->fresh()->status)->toBe(BookingStatus::Confirmed);
    $this->actingAs($provider->user)->post(route('provider.bookings.cancel', $pending->id))->assertSessionHasErrors('services');

    // In the other direction: with provider-services-manage used up, a fresh provider-bookings
    // counter (a new minute) lets the booking through.
    $this->travel(61)->seconds();
    $this->actingAs($provider->user)->post(route('provider.bookings.cancel', $pending->id))->assertSessionHasNoErrors();
    $this->actingAs($provider->user)->post(route('provider.bookings.cancel', $pending->id))->assertSessionHasErrors('services');
    pbsPost($provider->user, pbsData($service, ['starts_at' => PBS_TODAY.'T08:00:00Z']))->assertSessionHasNoErrors();
});

// ---------------------------------------------------------------------------------------------
// Model, list and texts
// ---------------------------------------------------------------------------------------------

test('created_by_provider is not fillable and is false by default', function () {
    $booking = new Booking(['created_by_provider' => true]);

    expect($booking->isFillable('created_by_provider'))->toBeFalse()
        ->and($booking->created_by_provider)->toBeFalse();

    $saved = Booking::factory()->create();
    expect($saved->fresh()->created_by_provider)->toBeFalse();
});

test('the bookings list says which bookings the provider wrote', function () {
    $provider = pbsProvider();
    $service = pbsService($provider);
    $fromCustomer = pbsExisting($provider, '10:00', '11:00', BookingStatus::Pending);
    pbsPost($provider->user, pbsData($service))->assertSessionHasNoErrors();
    $byProvider = Booking::query()->where('created_by_provider', true)->sole();

    $data = [];
    $this->actingAs($provider->user)->get(route('provider.bookings.index'))->assertOk()
        ->assertInertia(function ($page) use (&$data) {
            $data = $page->toArray()['props']['bookings']['data'];
        });

    expect(array_column($data, 'created_by_provider', 'id'))->toBe([
        $fromCustomer->id => false,
        $byProvider->id => true,
    ]);
});

test('the Italian texts are exactly the expected ones', function () {
    expect(__('provider.bookings.create.created'))->toBe('Prenotazione aggiunta e già confermata.');
    expect(__('provider.bookings.create.slot_unavailable'))->toBe('Questo orario non è disponibile. Scegli un altro orario.');
});
