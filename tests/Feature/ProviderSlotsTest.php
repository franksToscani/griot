<?php

use App\Enums\BookingStatus;
use App\Enums\Weekday;
use App\Models\Availability;
use App\Models\Booking;
use App\Models\Provider;
use App\Models\Service;
use App\Models\ServiceOption;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

// "Now" is Sunday 18 October 2026, 08:00 UTC (10:00 in Italy, summer time UTC+2). From there
// Monday 19 October 09:00-13:00 local is 07:00-11:00 UTC, the last day of the 60-day window is
// 17 December and the first day outside it is 18 December.
const SLOTS_MONDAY = '2026-10-19';

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-18 08:00', 'UTC'));
    app()->setLocale('it');
});

/**
 * A published provider working on Mondays in the given local windows ("HH:MM-HH:MM").
 */
function slotsApiProvider(string ...$windows): Provider
{
    $provider = Provider::factory()->published()->create();

    foreach ($windows === [] ? ['09:00-13:00'] : $windows as $window) {
        [$start, $end] = explode('-', $window);
        Availability::factory()->for($provider)->create([
            'weekday' => Weekday::Monday,
            'start_time' => "{$start}:00",
            'end_time' => "{$end}:00",
        ]);
    }

    return $provider;
}

function slotsApiService(Provider $provider, int $minutes = 60): Service
{
    return Service::factory()->for($provider)->create(['duration_min' => $minutes, 'duration_max' => $minutes]);
}

/**
 * The endpoint URL for a provider slug, with the given query parameters.
 *
 * @param  array<string, mixed>  $query
 */
function slotsApiUrl(Provider|string $provider, array $query): string
{
    $slug = $provider instanceof Provider ? $provider->slug : $provider;

    return route('providers.slots', ['publishedProvider' => $slug, ...$query]);
}

/**
 * The local times of the slots in a 200 response.
 *
 * @return list<string>
 */
function slotsApiTimes(Provider $provider, Service $service, string $date = SLOTS_MONDAY, array $options = []): array
{
    $query = ['service' => $service->id, 'date' => $date];

    if ($options !== []) {
        $query['options'] = $options;
    }

    return array_column(test()->getJson(slotsApiUrl($provider, $query))->assertOk()->json('slots'), 'time');
}

test('a normal day returns exactly the documented shape', function () {
    $provider = slotsApiProvider();
    $service = slotsApiService($provider);

    $response = $this->getJson(slotsApiUrl($provider, ['service' => $service->id, 'date' => SLOTS_MONDAY]));

    // 60-minute slots every 30 minutes in 07:00-11:00 UTC, that is 09:00-13:00 local.
    $response->assertOk()->assertExactJson([
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
    expect($response->json())->not->toHaveKey('data');
});

test('in winter the start is one hour later in UTC for the same local time', function () {
    $this->travelTo(CarbonImmutable::parse('2026-01-10 09:00', 'UTC'));
    $provider = slotsApiProvider();
    $service = slotsApiService($provider);

    // Winter time is UTC+1: 09:00 local on Monday 12 January is 08:00 UTC.
    $this->getJson(slotsApiUrl($provider, ['service' => $service->id, 'date' => '2026-01-12']))
        ->assertOk()
        ->assertJsonPath('slots.0', ['start' => '2026-01-12T08:00:00Z', 'time' => '09:00']);
});

test('an option with extra minutes makes the booking longer and removes the last slots', function () {
    $provider = slotsApiProvider();
    $service = slotsApiService($provider);
    $longHair = ServiceOption::factory()->for($service)->create(['extra_duration' => 60]);

    // 120 minutes in 09:00-13:00 local: the last slot starts at 11:00.
    expect(slotsApiTimes($provider, $service, options: [$longHair->id]))
        ->toBe(['09:00', '09:30', '10:00', '10:30', '11:00']);
});

test('a day without windows returns an empty list', function () {
    $provider = slotsApiProvider();
    $service = slotsApiService($provider);

    // Tuesday 20 October: the provider works only on Mondays.
    $this->getJson(slotsApiUrl($provider, ['service' => $service->id, 'date' => '2026-10-20']))
        ->assertOk()
        ->assertJsonPath('slots', []);
});

test('pending and confirmed bookings block slots, cancelled ones do not', function () {
    $provider = slotsApiProvider();
    $service = slotsApiService($provider);
    $book = fn (string $start, string $end, BookingStatus $status) => Booking::factory()->for($provider)->for($service)->create([
        'starts_at' => CarbonImmutable::parse("2026-10-19 {$start}", 'UTC'),
        'ends_at' => CarbonImmutable::parse("2026-10-19 {$end}", 'UTC'),
        'status' => $status,
    ]);
    // 09:00-10:00, 10:00-11:00 and 11:00-12:00 local.
    $book('07:00', '08:00', BookingStatus::Pending);
    $book('08:00', '09:00', BookingStatus::Cancelled);
    $book('09:00', '10:00', BookingStatus::Confirmed);

    expect(slotsApiTimes($provider, $service))->toBe(['10:00', '12:00']);
});

test('an inactive service returns an empty list, not an error', function () {
    $provider = slotsApiProvider();
    $service = Service::factory()->inactive()->for($provider)->create();

    $this->getJson(slotsApiUrl($provider, ['service' => $service->id, 'date' => SLOTS_MONDAY]))
        ->assertOk()
        ->assertJsonPath('slots', []);
});

test('an unpublished or missing provider gives a 404, even with wrong parameters', function () {
    $unpublished = slotsApiProvider();
    $unpublished->forceFill(['is_published' => false])->save();
    $service = slotsApiService($unpublished);
    $valid = ['service' => $service->id, 'date' => SLOTS_MONDAY];

    $this->getJson(slotsApiUrl($unpublished, $valid))->assertNotFound();
    $this->getJson(slotsApiUrl('non-esiste', $valid))->assertNotFound();
    // The provider is looked up before the parameters: no 422 that would reveal a slug.
    $this->getJson(slotsApiUrl($unpublished, ['service' => $service->id]))->assertNotFound();
    $this->getJson(slotsApiUrl('non-esiste', []))->assertNotFound();
});

test('a service of another provider and a missing service id get the same error', function () {
    $provider = slotsApiProvider();
    $otherService = slotsApiService(slotsApiProvider());
    $message = 'Il servizio scelto non è disponibile per questo professionista.';

    $this->getJson(slotsApiUrl($provider, ['service' => $otherService->id, 'date' => SLOTS_MONDAY]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['service' => $message]);
    $this->getJson(slotsApiUrl($provider, ['service' => 999999, 'date' => SLOTS_MONDAY]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['service' => $message]);
});

test('service and date are required', function () {
    $provider = slotsApiProvider();
    $service = slotsApiService($provider);

    $this->getJson(slotsApiUrl($provider, ['date' => SLOTS_MONDAY]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('service');
    $this->getJson(slotsApiUrl($provider, ['service' => $service->id]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('date');
});

test('a date in the wrong format is rejected', function (string $date) {
    $provider = slotsApiProvider();
    $service = slotsApiService($provider);

    $this->getJson(slotsApiUrl($provider, ['service' => $service->id, 'date' => $date]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('date');
})->with([
    'day first' => '18/10/2026',
    'impossible day' => '2026-02-30',
    'with a time' => '2026-10-19 10:00',
    'empty' => '',
]);

test('a day outside the booking window is rejected, its limits are accepted', function () {
    $provider = slotsApiProvider();
    $service = slotsApiService($provider);
    $request = fn (string $date) => $this->getJson(slotsApiUrl($provider, ['service' => $service->id, 'date' => $date]));

    // Yesterday and the day after the last one (18 October + 60 days = 17 December).
    foreach (['2026-10-17', '2026-12-18'] as $date) {
        $request($date)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('date');
        expect($request($date)->json('errors.date.0'))->toContain('60');
    }

    $request('2026-10-18')->assertOk();
    $request('2026-12-17')->assertOk();
});

test('options that are not options of the service are rejected', function () {
    $provider = slotsApiProvider();
    $service = slotsApiService($provider);
    $valid = ServiceOption::factory()->for($service)->create();
    $otherService = ServiceOption::factory()->for(slotsApiService($provider))->create();
    $otherProvider = ServiceOption::factory()->for(slotsApiService(slotsApiProvider()))->create();
    $message = 'Alcune opzioni scelte non appartengono a questo servizio.';

    foreach ([[$otherService->id], [$otherProvider->id], [999999], [$valid->id, $otherService->id]] as $options) {
        $this->getJson(slotsApiUrl($provider, ['service' => $service->id, 'date' => SLOTS_MONDAY, 'options' => $options]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['options' => $message]);
    }
});

test('option ids sent as strings in the query are accepted as integers', function () {
    $provider = slotsApiProvider();
    $service = slotsApiService($provider);
    $option = ServiceOption::factory()->for($service)->create(['extra_duration' => 60]);

    // The query string carries "options[]=<id>" as text.
    $url = "/providers/{$provider->slug}/slots?service={$service->id}&date=".SLOTS_MONDAY."&options[]={$option->id}";

    expect(array_column($this->getJson($url)->assertOk()->json('slots'), 'time'))
        ->toBe(['09:00', '09:30', '10:00', '10:30', '11:00']);
});

test('options in the wrong shape are rejected', function () {
    $provider = slotsApiProvider();
    $service = slotsApiService($provider);
    $base = "/providers/{$provider->slug}/slots?service={$service->id}&date=".SLOTS_MONDAY;

    $this->getJson("{$base}&options=3")->assertUnprocessable()->assertJsonValidationErrors('options');
    $this->getJson("{$base}&options[]=abc")->assertUnprocessable()->assertJsonValidationErrors('options.0');
    $this->getJson("{$base}&options[0][]=3")->assertUnprocessable()->assertJsonValidationErrors('options.0');

    $tooMany = implode('&', array_map(fn (int $id) => "options[]={$id}", range(1, 21)));
    $this->getJson("{$base}&{$tooMany}")->assertUnprocessable()->assertJsonValidationErrors('options');
});

test('the number of queries does not grow with the number of slots', function () {
    $countQueries = function (Provider $provider): array {
        $service = slotsApiService($provider, 30);

        DB::flushQueryLog();
        DB::enableQueryLog();
        $slots = $this->getJson(slotsApiUrl($provider, ['service' => $service->id, 'date' => SLOTS_MONDAY]))->assertOk()->json('slots');
        DB::disableQueryLog();

        return [count($slots), count(DB::getQueryLog())];
    };

    [$fewSlots, $fewQueries] = $countQueries(slotsApiProvider('09:00-11:30'));
    [$manySlots, $manyQueries] = $countQueries(slotsApiProvider('09:00-19:00'));

    expect($fewSlots)->toBe(5);
    expect($manySlots)->toBe(20);
    expect($manyQueries)->toBe($fewQueries);
});

test('the three Italian messages are the expected ones', function () {
    $provider = slotsApiProvider();
    $service = slotsApiService($provider);
    $foreignOption = ServiceOption::factory()->for(slotsApiService(slotsApiProvider()))->create();

    expect($this->getJson(slotsApiUrl($provider, ['service' => $service->id, 'date' => '2026-10-17']))->json('errors.date.0'))
        ->toBe('Scegli un giorno da oggi fino a 60 giorni da adesso.');
    expect($this->getJson(slotsApiUrl($provider, ['service' => 999999, 'date' => SLOTS_MONDAY]))->json('errors.service.0'))
        ->toBe('Il servizio scelto non è disponibile per questo professionista.');
    expect($this->getJson(slotsApiUrl($provider, ['service' => $service->id, 'date' => SLOTS_MONDAY, 'options' => [$foreignOption->id]]))->json('errors.options.0'))
        ->toBe('Alcune opzioni scelte non appartengono a questo servizio.');
});
