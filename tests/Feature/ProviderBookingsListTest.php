<?php

use App\Booking\LocalTime;
use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Provider;
use App\Models\Service;
use App\Models\ServiceOption;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;

// "Now" is Tuesday 20 October 2026, 10:00 UTC (12:00 in Italy, summer time until 25 October).
beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-20 10:00', 'UTC'));
    app()->setLocale('it');
});

const PBL_STATUS_INVALID = 'Scegli uno stato valido.';
const PBL_DATE_INVALID = 'Scrivi la data come giorno, mese e anno.';
const PBL_RANGE_ORDER = 'La data di fine non può essere prima di quella di inizio.';
const PBL_RANGE_TOO_LONG = "L'intervallo può essere al massimo di 366 giorni.";

function pblProvider(string $name = 'Salone Awa'): Provider
{
    return Provider::factory()->create(['name' => $name, 'is_published' => true]);
}

function pblService(Provider $provider, string $price = '25.00', string $name = 'Box braids'): Service
{
    return Service::factory()->for($provider)->create(['name' => $name, 'price' => $price]);
}

/**
 * A booking of the provider starting at the given UTC instant ("Y-m-d H:i").
 *
 * @param  array<string, mixed>  $override
 */
function pblBooking(Provider $provider, string $startsAtUtc, BookingStatus $status = BookingStatus::Pending, int $minutes = 60, array $override = []): Booking
{
    $start = CarbonImmutable::parse($startsAtUtc, 'UTC');

    return Booking::factory()->for($provider)->for($override['service'] ?? pblService($provider))->create([
        'starts_at' => $start,
        'ends_at' => $start->addMinutes($minutes),
        'status' => $status,
        ...array_diff_key($override, ['service' => true]),
    ]);
}

/**
 * @param  array<string, mixed>  $query
 */
function pblGet(User $user, array $query = []): TestResponse
{
    return test()->actingAs($user)->get(route('provider.bookings.index', $query));
}

/**
 * The props of the bookings page, read with assertInertia().
 *
 * @return array<string, mixed>
 */
function pblPropsOf(TestResponse $response): array
{
    $props = [];
    $response->assertOk()->assertInertia(function (Assert $page) use (&$props) {
        $page->component('provider/bookings/index');
        $props = $page->toArray()['props'];
    });

    return $props;
}

/**
 * @param  array<string, mixed>  $query
 * @return array<string, mixed>
 */
function pblProps(User $user, array $query = []): array
{
    return pblPropsOf(pblGet($user, $query));
}

/**
 * The ids of the bookings on the page, in their order.
 *
 * @param  array<string, mixed>  $query
 * @return list<int>
 */
function pblIds(User $user, array $query = []): array
{
    return array_column(pblProps($user, $query)['bookings']['data'], 'id');
}

test('guests, unverified users and users without a provider are sent away', function () {
    $this->get(route('provider.bookings.index'))->assertRedirect(route('login'));

    $unverified = User::factory()->unverified()->create();
    Provider::factory()->create(['user_id' => $unverified->id]);
    $this->actingAs($unverified)->get(route('provider.bookings.index'))->assertRedirect(route('verification.notice'));

    $this->actingAs(User::factory()->create())->get(route('provider.bookings.index'))->assertRedirect(route('provider.setup'));
});

test('a provider sees only its own bookings', function () {
    $mine = pblProvider();
    $theirs = pblProvider('Barberia Kofi');
    $own = pblBooking($mine, '2026-10-21 08:00', override: ['customer_name' => 'Awa Diop']);
    pblBooking($theirs, '2026-10-21 08:00', override: ['customer_name' => 'Cliente Di Kofi']);

    $response = pblGet($mine->user);

    expect(array_column(pblPropsOf($response)['bookings']['data'], 'id'))->toBe([$own->id]);
    $response->assertSee('Awa Diop')->assertDontSee('Cliente Di Kofi');
});

test('the query string and the body cannot choose another provider', function () {
    $mine = pblProvider();
    $theirs = pblProvider('Barberia Kofi');
    $own = pblBooking($mine, '2026-10-21 08:00');
    pblBooking($theirs, '2026-10-21 08:00');

    expect(pblIds($mine->user, ['provider_id' => $theirs->id, 'user_id' => $theirs->user_id]))->toBe([$own->id]);
});

test('each booking has exactly the expected keys, and the private token is nowhere', function () {
    $provider = pblProvider();
    $service = pblService($provider, '25.00', 'Box braids');
    $option = ServiceOption::factory()->for($service)->create(['name' => 'Perline', 'extra_price' => '7.50']);
    // 09:00-10:15 in Italy.
    $booking = pblBooking($provider, '2026-10-21 07:00', BookingStatus::Confirmed, 75, [
        'service' => $service,
        'customer_name' => 'Awa Diop',
        'customer_phone' => '+39 333 123 4567',
        'customer_email' => 'awa@example.com',
        'notes' => 'Capelli lunghi',
    ]);
    $booking->options()->attach($option->id);

    $response = pblGet($provider->user);
    $props = pblPropsOf($response);

    expect($props['bookings']['data'])->toBe([[
        'id' => $booking->id,
        'status' => 'confirmed',
        'local_date' => '2026-10-21',
        'local_start' => '09:00',
        'local_end' => '10:15',
        'customer_name' => 'Awa Diop',
        'customer_phone' => '+39 333 123 4567',
        'customer_email' => 'awa@example.com',
        'notes' => 'Capelli lunghi',
        'service' => ['name' => 'Box braids'],
        'options' => [['name' => 'Perline', 'extra_price' => '7.50']],
        'price_cents' => 3250,
        // Confirmed, in the future: only the provider's cancellation is allowed.
        'can_confirm' => false,
        'can_reject' => false,
        'can_cancel' => true,
    ]]);
    expect($props['filters'])->toBe(['status' => null, 'from' => null, 'to' => null]);
    expect($response->getContent())->not->toContain($booking->public_token)->not->toContain('public_token');
});

test('the pending ones come first, then the others; each group by start, then by id', function () {
    $provider = pblProvider();
    $confirmedEarly = pblBooking($provider, '2026-10-21 08:00', BookingStatus::Confirmed);
    $pendingLate = pblBooking($provider, '2026-10-25 08:00');
    $cancelled = pblBooking($provider, '2026-10-22 08:00', BookingStatus::Cancelled);
    $pendingEarly = pblBooking($provider, '2026-10-23 08:00');
    $pendingSameStart = pblBooking($provider, '2026-10-23 08:00');

    expect(pblIds($provider->user))->toBe([$pendingEarly->id, $pendingSameStart->id, $pendingLate->id, $confirmedEarly->id, $cancelled->id]);
});

test('without filters: what ends from now on, and every pending one, even past', function () {
    $provider = pblProvider();
    $pastPending = pblBooking($provider, '2026-10-01 08:00');
    pblBooking($provider, '2026-10-01 09:00', BookingStatus::Confirmed);
    pblBooking($provider, '2026-10-01 10:00', BookingStatus::Cancelled);
    // Ended exactly one minute ago.
    pblBooking($provider, '2026-10-20 08:59', BookingStatus::Confirmed);
    // Started before now, still going on: it ends at 10:30.
    $ongoing = pblBooking($provider, '2026-10-20 09:30', BookingStatus::Confirmed);
    $futureConfirmed = pblBooking($provider, '2026-10-22 08:00', BookingStatus::Confirmed);
    $futureCancelled = pblBooking($provider, '2026-10-23 08:00', BookingStatus::Cancelled);

    expect(pblIds($provider->user))->toBe([$pastPending->id, $ongoing->id, $futureConfirmed->id, $futureCancelled->id]);
});

test('a status filter shows that status only, past and future', function () {
    $provider = pblProvider();
    $pastConfirmed = pblBooking($provider, '2026-10-01 08:00', BookingStatus::Confirmed);
    $futureConfirmed = pblBooking($provider, '2026-10-22 08:00', BookingStatus::Confirmed);
    pblBooking($provider, '2026-10-22 09:00');
    $cancelled = pblBooking($provider, '2026-10-01 09:00', BookingStatus::Cancelled);

    expect(pblIds($provider->user, ['status' => 'confirmed']))->toBe([$pastConfirmed->id, $futureConfirmed->id]);
    expect(pblIds($provider->user, ['status' => 'cancelled']))->toBe([$cancelled->id]);

    expect(pblProps($provider->user, ['status' => 'confirmed'])['filters'])->toBe(['status' => 'confirmed', 'from' => null, 'to' => null]);
});

test('dates are local days, both ends included, each one on its own too', function () {
    $provider = pblProvider();
    // Summer time (UTC+2): the local 10 October starts at 22:00 UTC of the 9th.
    pblBooking($provider, '2026-10-09 21:59', BookingStatus::Confirmed);
    $firstMinute = pblBooking($provider, '2026-10-09 22:00', BookingStatus::Confirmed);
    $lastMinute = pblBooking($provider, '2026-10-12 21:59', BookingStatus::Confirmed);
    $nextDay = pblBooking($provider, '2026-10-12 22:00', BookingStatus::Confirmed);
    $pending = pblBooking($provider, '2026-10-11 08:00');

    expect(pblIds($provider->user, ['from' => '2026-10-10', 'to' => '2026-10-12']))->toBe([$pending->id, $firstMinute->id, $lastMinute->id]);
    expect(pblIds($provider->user, ['from' => '2026-10-12']))->toBe([$lastMinute->id, $nextDay->id]);
    expect(pblIds($provider->user, ['to' => '2026-10-10']))->toHaveCount(2);
    expect(pblIds($provider->user, ['from' => '2026-10-10', 'to' => '2026-10-12', 'status' => 'confirmed']))->toBe([$firstMinute->id, $lastMinute->id]);
});

test('when the clocks go back, 25 October 2026 lasts 25 hours', function () {
    $provider = pblProvider();
    // Until 01:00 UTC Italy is UTC+2, then UTC+1: the local day goes from 22:00 UTC of the 24th
    // to 23:00 UTC of the 25th.
    pblBooking($provider, '2026-10-24 21:59', BookingStatus::Confirmed);
    $first = pblBooking($provider, '2026-10-24 22:00', BookingStatus::Confirmed);
    $afterTheChange = pblBooking($provider, '2026-10-25 01:30', BookingStatus::Confirmed);
    $last = pblBooking($provider, '2026-10-25 22:59', BookingStatus::Confirmed);
    pblBooking($provider, '2026-10-25 23:00', BookingStatus::Confirmed);

    expect(pblIds($provider->user, ['from' => '2026-10-25', 'to' => '2026-10-25']))->toBe([$first->id, $afterTheChange->id, $last->id]);

    expect(LocalTime::dayStart('2026-10-25')->toIso8601String())->toBe('2026-10-24T22:00:00+00:00');
    expect(LocalTime::dayEnd('2026-10-25')->toIso8601String())->toBe('2026-10-25T23:00:00+00:00');
    expect(LocalTime::dayStart('2026-10-25')->diffInHours(LocalTime::dayEnd('2026-10-25')))->toEqual(25);
});

test('when the clocks go forward, 29 March 2026 lasts 23 hours; winter and summer days last 24', function () {
    expect(LocalTime::dayStart('2026-03-29')->toIso8601String())->toBe('2026-03-28T23:00:00+00:00');
    expect(LocalTime::dayEnd('2026-03-29')->toIso8601String())->toBe('2026-03-29T22:00:00+00:00');
    expect(LocalTime::dayStart('2026-03-29')->diffInHours(LocalTime::dayEnd('2026-03-29')))->toEqual(23);

    // Winter: UTC+1. Summer: UTC+2.
    expect(LocalTime::dayStart('2026-01-12')->toIso8601String())->toBe('2026-01-11T23:00:00+00:00');
    expect(LocalTime::dayEnd('2026-01-12')->toIso8601String())->toBe('2026-01-12T23:00:00+00:00');
    expect(LocalTime::dayStart('2026-07-06')->toIso8601String())->toBe('2026-07-05T22:00:00+00:00');
    expect(LocalTime::dayEnd('2026-07-06')->toIso8601String())->toBe('2026-07-06T22:00:00+00:00');
});

test('the local time of a booking in winter and in summer', function (string $startsAtUtc, string $date, string $start, string $end) {
    $provider = pblProvider();
    pblBooking($provider, $startsAtUtc, BookingStatus::Confirmed, 90);

    $row = pblProps($provider->user, ['status' => 'confirmed'])['bookings']['data'][0];

    expect([$row['local_date'], $row['local_start'], $row['local_end']])->toBe([$date, $start, $end]);
})->with([
    'winter, UTC+1' => ['2026-01-12 08:00', '2026-01-12', '09:00', '10:30'],
    'summer, UTC+2' => ['2026-07-06 07:00', '2026-07-06', '09:00', '10:30'],
    'late at night in UTC, already the next day in Italy' => ['2026-07-06 22:30', '2026-07-07', '00:30', '02:00'],
]);

test('invalid filters go back to the list without filters, with the message on their field', function (array $query, string $field, string $message) {
    $provider = pblProvider();

    pblGet($provider->user, $query)
        ->assertRedirect(route('provider.bookings.index'))
        ->assertSessionHasErrors([$field => $message]);
})->with([
    'unknown status' => [['status' => 'done'], 'status', PBL_STATUS_INVALID],
    'status as a list' => [['status' => ['pending']], 'status', PBL_STATUS_INVALID],
    'status in capitals' => [['status' => 'PENDING'], 'status', PBL_STATUS_INVALID],
    'day first' => [['from' => '20-10-2026'], 'from', PBL_DATE_INVALID],
    'month 13' => [['from' => '2026-13-01'], 'from', PBL_DATE_INVALID],
    '30 February' => [['to' => '2026-02-30'], 'to', PBL_DATE_INVALID],
    'with a time' => [['to' => '2026-10-20 10:00'], 'to', PBL_DATE_INVALID],
    'HTML' => [['from' => '<b>2026-10-20</b>'], 'from', PBL_DATE_INVALID],
    'end before start' => [['from' => '2026-10-20', 'to' => '2026-10-19'], 'to', PBL_RANGE_ORDER],
    '367 days' => [['from' => '2026-01-01', 'to' => '2027-01-02'], 'to', PBL_RANGE_TOO_LONG],
]);

test('a JSON client gets a 422 with the same messages', function () {
    $provider = pblProvider();

    $this->actingAs($provider->user)
        ->getJson(route('provider.bookings.index', ['status' => 'done', 'from' => '2026-10-20', 'to' => '2026-10-19']))
        ->assertUnprocessable()
        ->assertJsonPath('errors.status.0', PBL_STATUS_INVALID)
        ->assertJsonPath('errors.to.0', PBL_RANGE_ORDER);
});

test('accepted ranges: one day, 366 days of a leap year, a single far end', function (array $query) {
    $provider = pblProvider();

    pblGet($provider->user, $query)->assertOk()->assertSessionHasNoErrors();
})->with([
    'one day' => [['from' => '2026-10-20', 'to' => '2026-10-20']],
    '366 days, both ends included' => [['from' => '2028-01-01', 'to' => '2028-12-31']],
    'from only, years ago' => [['from' => '2020-01-01']],
    'to only, years ahead' => [['to' => '2030-12-31']],
    'empty values are no filter' => [['status' => '', 'from' => '', 'to' => '']],
]);

test('an invalid "from" gets its own error, not a range one', function () {
    $provider = pblProvider();

    pblGet($provider->user, ['from' => 'ieri', 'to' => '2026-10-19'])
        ->assertSessionHasErrors(['from' => PBL_DATE_INVALID])
        ->assertSessionDoesntHaveErrors(['to']);
});

test('20 per page, and the page links keep the filters', function () {
    $provider = pblProvider();
    $service = pblService($provider);

    for ($i = 0; $i < 25; $i++) {
        pblBooking($provider, CarbonImmutable::parse('2026-10-21 08:00', 'UTC')->addDays($i)->format('Y-m-d H:i'), BookingStatus::Confirmed, override: ['service' => $service]);
    }

    $first = pblProps($provider->user, ['status' => 'confirmed'])['bookings'];
    expect($first['data'])->toHaveCount(20);
    expect($first['meta']['current_page'])->toBe(1);
    expect($first['meta']['last_page'])->toBe(2);
    expect($first['links']['next'])->toContain('status=confirmed')->toContain('page=2');

    $second = pblProps($provider->user, ['status' => 'confirmed', 'page' => 2])['bookings'];
    expect($second['data'])->toHaveCount(5);
    expect($second['links']['prev'])->toContain('status=confirmed');

    // No filter, no filter in the links.
    expect(pblProps($provider->user)['bookings']['links']['next'])->not->toContain('status=');
});

test('the price in cents: the current price of the service plus the options chosen, with integers only', function (string $price, array $extras, int $cents) {
    $provider = pblProvider();
    $service = pblService($provider, $price);
    $booking = pblBooking($provider, '2026-10-21 08:00', override: ['service' => $service]);

    foreach ($extras as $i => $extra) {
        $booking->options()->attach(ServiceOption::factory()->for($service)->create(['name' => "Opzione {$i}", 'extra_price' => $extra])->id);
    }

    expect(pblProps($provider->user)['bookings']['data'][0]['price_cents'])->toBe($cents);
})->with([
    'no options' => ['90.00', [], 9000],
    'with one option' => ['25.00', ['7.50'], 3250],
    'cents that float would round' => ['0.10', ['0.20', '0.70'], 100],
    'free options' => ['30.00', ['0.00', '0.00'], 3000],
    'the highest prices' => ['9999.99', ['999.99', '999.99'], 1199997],
]);

test('a customer without email and notes gets null for both', function () {
    $provider = pblProvider();
    pblBooking($provider, '2026-10-21 08:00', override: ['customer_email' => null, 'notes' => null]);

    $row = pblProps($provider->user)['bookings']['data'][0];

    expect($row['customer_email'])->toBeNull();
    expect($row['notes'])->toBeNull();
    expect($row['options'])->toBe([]);
});

test('HTML and Markdown in the customer fields stay plain strings', function () {
    $provider = pblProvider();
    pblBooking($provider, '2026-10-21 08:00', override: [
        'customer_name' => '<script>alert(1)</script>',
        'notes' => "**grassetto** [link](https://example.com)\n# titolo",
    ]);

    $row = pblProps($provider->user)['bookings']['data'][0];

    expect($row['customer_name'])->toBe('<script>alert(1)</script>');
    expect($row['notes'])->toBe("**grassetto** [link](https://example.com)\n# titolo");
});

test('the number of queries does not grow with bookings and options', function () {
    // A provider each: actingAs() keeps the same User object across requests of one user.
    $count = function (int $bookings, int $options) {
        $provider = pblProvider("Salone {$bookings} {$options}");
        $service = pblService($provider);
        $chosen = ServiceOption::factory()->count($options)->for($service)->create()->modelKeys();

        for ($i = 0; $i < $bookings; $i++) {
            pblBooking($provider, '2026-10-21 08:00', override: ['service' => $service])->options()->attach($chosen);
        }

        $queries = 0;
        DB::listen(function () use (&$queries) {
            $queries++;
        });
        pblGet($provider->user)->assertOk();

        return $queries;
    };

    $base = $count(1, 0);

    expect($count(5, 0))->toBe($base);
    expect($count(1, 3))->toBe($base);
    expect($count(5, 3))->toBe($base);
});

test('the list only reads: other methods are not allowed', function () {
    $provider = pblProvider();

    $this->actingAs($provider->user)->post('/provider/bookings')->assertMethodNotAllowed();
    $this->actingAs($provider->user)->put('/provider/bookings')->assertMethodNotAllowed();
    $this->actingAs($provider->user)->delete('/provider/bookings')->assertMethodNotAllowed();
    expect(route('provider.bookings.index'))->toBe(url('/provider/bookings'));
});

test('the Italian texts are exactly the expected ones', function () {
    $texts = [
        'provider.bookings.validation.status_invalid' => PBL_STATUS_INVALID,
        'provider.bookings.validation.date_invalid' => PBL_DATE_INVALID,
        'provider.bookings.validation.range_order' => PBL_RANGE_ORDER,
        'provider.bookings.validation.range_too_long' => PBL_RANGE_TOO_LONG,
    ];

    foreach ($texts as $key => $text) {
        expect(__($key))->toBe($text);
    }

    expect(PBL_STATUS_INVALID)->toBe('Scegli uno stato valido.');
    expect(PBL_DATE_INVALID)->toBe('Scrivi la data come giorno, mese e anno.');
    expect(PBL_RANGE_ORDER)->toBe('La data di fine non può essere prima di quella di inizio.');
    expect(PBL_RANGE_TOO_LONG)->toBe("L'intervallo può essere al massimo di 366 giorni.");
});

test('the texts of the bookings page are exactly the expected ones', function () {
    $texts = [
        'provider.bookings.title' => 'Le tue prenotazioni',
        'provider.bookings.help' => 'Senza filtri vedi le prenotazioni in arrivo e le richieste ancora in attesa, anche passate.',
        'provider.bookings.filters_active' => 'Con i filtri attivi vedi anche le prenotazioni passate.',
        'provider.bookings.empty' => 'Non hai prenotazioni in arrivo.',
        'provider.bookings.empty_filtered' => 'Nessuna prenotazione corrisponde ai filtri.',
        'provider.bookings.filter.status' => 'Stato',
        'provider.bookings.filter.status_all' => 'Tutti',
        'provider.bookings.filter.from' => 'Dal',
        'provider.bookings.filter.to' => 'Al',
        'provider.bookings.filter.submit' => 'Filtra',
        'provider.bookings.filter.reset' => 'Azzera',
        'provider.bookings.status.pending' => 'In attesa',
        'provider.bookings.status.confirmed' => 'Confermata',
        'provider.bookings.status.cancelled' => 'Annullata',
        'provider.bookings.phone' => 'Telefono',
        'provider.bookings.email' => 'Email',
        'provider.bookings.notes' => 'Note',
        'provider.bookings.price_note' => 'Prezzo indicativo',
        'provider.area.bookings' => 'Le tue prenotazioni',
    ];

    foreach ($texts as $key => $text) {
        expect(__($key))->toBe($text);
    }
});

test('the page shows the customer fields as plain text: no phone or mail links, no raw HTML', function () {
    // Read in the page source: there is no JavaScript test runner.
    $source = file_get_contents(resource_path('js/pages/provider/bookings/index.tsx'));

    expect($source)
        ->not->toContain('tel:')
        ->not->toContain('mailto:')
        ->not->toContain('dangerouslySetInnerHTML')
        // The fields are printed as JSX expressions, which React escapes.
        ->toContain('{booking.customer_name}')
        ->toContain('{booking.customer_phone}')
        ->toContain('{booking.notes}');
});
