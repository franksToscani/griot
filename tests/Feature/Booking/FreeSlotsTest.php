<?php

use App\Booking\FreeSlots;
use App\Booking\InvalidBookingOptions;
use App\Booking\ServiceNotOfProvider;
use App\Booking\TimeWindow;
use App\Enums\BookingStatus;
use App\Enums\Weekday;
use App\Models\Availability;
use App\Models\Booking;
use App\Models\Provider;
use App\Models\Service;
use App\Models\ServiceOption;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

// Fixed dates: Monday 12 January 2026 (winter, Italy is UTC+1) and Monday 6 July 2026
// (summer, Italy is UTC+2). "Now" is always set with travelTo, never the real date.
const WINTER_MONDAY = '2026-01-12';
const SUMMER_MONDAY = '2026-07-06';

beforeEach(function () {
    // A week before the winter Monday: the day is inside the booking window and far from the notice.
    $this->travelTo(CarbonImmutable::parse('2026-01-05 08:00', 'UTC'));
});

/**
 * A published provider working on Mondays in the given local windows ("HH:MM-HH:MM").
 */
function mondayProvider(string ...$windows): Provider
{
    $provider = Provider::factory()->published()->create();

    foreach ($windows as $window) {
        [$start, $end] = explode('-', $window);
        Availability::factory()->for($provider)->create([
            'weekday' => Weekday::Monday,
            'start_time' => "{$start}:00",
            'end_time' => "{$end}:00",
        ]);
    }

    return $provider->load('availabilities');
}

function serviceBlocking(Provider $provider, int $minutes): Service
{
    return Service::factory()->for($provider)->create(['duration_min' => $minutes, 'duration_max' => $minutes]);
}

/**
 * A booking between two local times of the given day.
 */
function bookLocally(Provider $provider, string $day, string $start, string $end, BookingStatus $status = BookingStatus::Pending): Booking
{
    $timezone = config()->string('booking.timezone');

    return Booking::factory()->for($provider)->for(Service::factory()->for($provider))->create([
        'starts_at' => CarbonImmutable::parse("{$day} {$start}", $timezone)->utc(),
        'ends_at' => CarbonImmutable::parse("{$day} {$end}", $timezone)->utc(),
        'status' => $status,
    ]);
}

/**
 * The start of each free slot as local "HH:MM".
 *
 * @param  list<int>  $optionIds
 * @return list<string>
 */
function freeStarts(Provider $provider, Service $service, string $day = WINTER_MONDAY, array $optionIds = []): array
{
    $timezone = config()->string('booking.timezone');

    return app(FreeSlots::class)
        ->slotsFor($provider, $service, $optionIds, CarbonImmutable::parse($day))
        ->map(fn (TimeWindow $slot) => $slot->start->setTimezone($timezone)->format('H:i'))
        ->all();
}

test('the test days are Mondays', function () {
    expect(CarbonImmutable::parse(WINTER_MONDAY)->dayOfWeekIso)->toBe(Weekday::Monday->value);
    expect(CarbonImmutable::parse(SUMMER_MONDAY)->dayOfWeekIso)->toBe(Weekday::Monday->value);
});

test('a day without windows has no slots and does not read the bookings', function () {
    $provider = mondayProvider('09:00-13:00');
    $service = serviceBlocking($provider, 60);

    DB::flushQueryLog();
    DB::enableQueryLog();
    // 13 January 2026 is a Tuesday.
    $slots = app(FreeSlots::class)->slotsFor($provider, $service, [], CarbonImmutable::parse('2026-01-13'));
    DB::disableQueryLog();

    expect($slots)->toBeEmpty();
    expect(collect(DB::getQueryLog())->pluck('query')->filter(fn ($sql) => str_contains($sql, 'bookings')))->toBeEmpty();
});

test('slots start every step and the last one ends exactly at the end of the window', function () {
    $provider = mondayProvider('09:00-13:00');

    expect(freeStarts($provider, serviceBlocking($provider, 60)))
        ->toBe(['09:00', '09:30', '10:00', '10:30', '11:00', '11:30', '12:00']);
    expect(freeStarts($provider, serviceBlocking($provider, 90)))
        ->toBe(['09:00', '09:30', '10:00', '10:30', '11:00', '11:30']);
});

test('a booking longer than the window has no slots', function () {
    $provider = mondayProvider('09:00-13:00');

    expect(freeStarts($provider, serviceBlocking($provider, 300)))->toBe([]);
});

test('two windows give the slots of both, in order', function () {
    // Created afternoon first, to check the order.
    $provider = mondayProvider('15:00-19:00', '09:00-13:00');

    expect(freeStarts($provider, serviceBlocking($provider, 180)))
        ->toBe(['09:00', '09:30', '10:00', '15:00', '15:30', '16:00']);
});

test('options make the booking longer, so they remove slots at the end of the window', function () {
    $provider = mondayProvider('09:00-13:00');
    $service = serviceBlocking($provider, 60);
    $longHair = ServiceOption::factory()->for($service)->create(['extra_duration' => 30]);

    expect(freeStarts($provider, $service, optionIds: [$longHair->id]))
        ->toBe(['09:00', '09:30', '10:00', '10:30', '11:00', '11:30']);
});

test('pending and confirmed bookings block the slots they overlap, cancelled ones do not', function () {
    $provider = mondayProvider('09:00-13:00');
    $service = serviceBlocking($provider, 60);
    bookLocally($provider, WINTER_MONDAY, '09:00', '10:00', BookingStatus::Pending);
    bookLocally($provider, WINTER_MONDAY, '11:00', '12:00', BookingStatus::Confirmed);
    bookLocally($provider, WINTER_MONDAY, '10:00', '11:00', BookingStatus::Cancelled);

    expect(freeStarts($provider, $service))->toBe(['10:00', '12:00']);
});

test('back-to-back slots around a booking are free', function () {
    $provider = mondayProvider('09:00-13:00');
    $service = serviceBlocking($provider, 60);
    bookLocally($provider, WINTER_MONDAY, '10:00', '11:00');

    $starts = freeStarts($provider, $service);

    expect($starts)->toContain('09:00', '11:00');
    expect($starts)->not->toContain('09:30', '10:00', '10:30');
});

test('bookings of another provider or of another day do not block', function () {
    $provider = mondayProvider('09:00-13:00');
    $service = serviceBlocking($provider, 60);
    bookLocally(mondayProvider('09:00-13:00'), WINTER_MONDAY, '09:00', '13:00');
    bookLocally($provider, '2026-01-19', '09:00', '13:00');

    expect(freeStarts($provider, $service))->toHaveCount(7);
});

test('a booking that starts before the window and enters it blocks the first slots', function () {
    $provider = mondayProvider('09:00-13:00');
    $service = serviceBlocking($provider, 60);
    bookLocally($provider, WINTER_MONDAY, '08:00', '09:45');

    expect(freeStarts($provider, $service))->toBe(['10:00', '10:30', '11:00', '11:30', '12:00']);
});

test('the minimum notice removes the slots starting too soon', function () {
    $provider = mondayProvider('09:00-18:00');
    $service = serviceBlocking($provider, 60);
    // 10:10 in Rome on the same Monday; with 120 minutes the earliest start is 12:10.
    $this->travelTo(CarbonImmutable::parse(WINTER_MONDAY.' 10:10', 'Europe/Rome'));

    expect(freeStarts($provider, $service)[0])->toBe('12:30');
});

test('a past day has no slots, and today only those after the notice', function () {
    $provider = mondayProvider('09:00-13:00');
    $service = serviceBlocking($provider, 60);

    $this->travelTo(CarbonImmutable::parse('2026-01-13 08:00', 'UTC'));
    expect(freeStarts($provider, $service))->toBe([]);

    // Monday 08:30 in Rome: the notice ends at 10:30.
    $this->travelTo(CarbonImmutable::parse(WINTER_MONDAY.' 08:30', 'Europe/Rome'));
    expect(freeStarts($provider, $service))->toBe(['10:30', '11:00', '11:30', '12:00']);
});

test('the booking window goes from today to today plus 60 days', function () {
    $this->travelTo(CarbonImmutable::parse(WINTER_MONDAY.' 10:00', 'Europe/Rome'));
    $freeSlots = app(FreeSlots::class);

    expect($freeSlots->isWithinWindow(CarbonImmutable::parse(WINTER_MONDAY)))->toBeTrue();
    expect($freeSlots->isWithinWindow(CarbonImmutable::parse('2026-03-13')))->toBeTrue();
    expect($freeSlots->isWithinWindow(CarbonImmutable::parse('2026-03-14')))->toBeFalse();
    expect($freeSlots->isWithinWindow(CarbonImmutable::parse('2026-01-11')))->toBeFalse();
});

test('the booking window uses only the calendar date received, and today in the booking time zone', function () {
    $freeSlots = app(FreeSlots::class);

    // Saturday 17 October at noon, in Italy too. The last day of the window is 16 December.
    $this->travelTo(CarbonImmutable::parse('2026-10-17 12:00', 'UTC'));
    // Saturday 23:30 UTC is already Sunday in Italy, but the date received is Saturday: today.
    expect($freeSlots->isWithinWindow(CarbonImmutable::parse('2026-10-17 23:30', 'UTC')))->toBeTrue();
    // 16 December 23:30 UTC is 17 December in Italy, but the date received is the last day.
    expect($freeSlots->isWithinWindow(CarbonImmutable::parse('2026-12-16 23:30', 'UTC')))->toBeTrue();

    // Now Saturday 23:30 UTC: in Italy today is already Sunday 18, so Saturday is in the past.
    $this->travelTo(CarbonImmutable::parse('2026-10-17 23:30', 'UTC'));
    expect($freeSlots->isWithinWindow(CarbonImmutable::parse('2026-10-17')))->toBeFalse();
    expect($freeSlots->isWithinWindow(CarbonImmutable::parse('2026-10-18')))->toBeTrue();
});

test('an inactive service has no slots', function () {
    $provider = mondayProvider('09:00-13:00');
    $service = Service::factory()->inactive()->for($provider)->create();

    expect(freeStarts($provider, $service))->toBe([]);
});

test('an unpublished provider has no slots', function () {
    $provider = mondayProvider('09:00-13:00');
    $provider->update(['is_published' => false]);

    expect(freeStarts($provider, serviceBlocking($provider, 60)))->toBe([]);
});

test('a service of another provider is rejected', function () {
    $provider = mondayProvider('09:00-13:00');
    $otherService = serviceBlocking(mondayProvider('09:00-13:00'), 60);

    expect(fn () => freeStarts($provider, $otherService))->toThrow(ServiceNotOfProvider::class);
});

test('invalid options are rejected, even on a day outside the booking window', function () {
    $provider = mondayProvider('09:00-13:00');
    $service = serviceBlocking($provider, 60);
    $foreignOption = ServiceOption::factory()->for(serviceBlocking($provider, 30))->create();

    expect(fn () => freeStarts($provider, $service, optionIds: [$foreignOption->id]))
        ->toThrow(InvalidBookingOptions::class);
    // A Monday a year later: far outside the window, the input is still checked first.
    expect(fn () => freeStarts($provider, $service, '2027-01-11', [$foreignOption->id]))
        ->toThrow(InvalidBookingOptions::class);
});

test('slots are in UTC: in summer 09:00 local is 07:00 UTC', function () {
    $this->travelTo(CarbonImmutable::parse('2026-07-01 08:00', 'UTC'));
    $provider = mondayProvider('09:00-13:00');
    $service = serviceBlocking($provider, 60);

    $first = app(FreeSlots::class)->slotsFor($provider, $service, [], CarbonImmutable::parse(SUMMER_MONDAY))->first();

    expect($first->start->timezoneName)->toBe('UTC');
    expect($first->start->toIso8601String())->toBe('2026-07-06T07:00:00+00:00');
    expect($first->end->toIso8601String())->toBe('2026-07-06T08:00:00+00:00');
});

test('overlapping windows do not give duplicate slots', function () {
    $provider = mondayProvider('09:00-12:00', '10:00-13:00');

    expect(freeStarts($provider, serviceBlocking($provider, 60)))
        ->toBe(['09:00', '09:30', '10:00', '10:30', '11:00', '11:30', '12:00']);
});

test('the step, the notice and the window come from the booking configuration', function () {
    $provider = mondayProvider('09:00-10:00');
    $service = serviceBlocking($provider, 30);

    config()->set('booking.slot_step_minutes', 15);
    expect(freeStarts($provider, $service))->toBe(['09:00', '09:15', '09:30']);

    // Monday 08:50 in Rome: a 30-minute notice leaves only 09:30.
    config()->set('booking.minimum_notice_minutes', 30);
    $this->travelTo(CarbonImmutable::parse(WINTER_MONDAY.' 08:50', 'Europe/Rome'));
    expect(freeStarts($provider, $service))->toBe(['09:30']);

    // A 5-day window from 5 January ends on the 10th: the 12th is outside.
    config()->set('booking.max_days_ahead', 5);
    $this->travelTo(CarbonImmutable::parse('2026-01-05 08:00', 'UTC'));
    expect(freeStarts($provider, $service))->toBe([]);
});

test('the number of queries does not grow with the number of slots', function () {
    $countQueries = function (Provider $provider): array {
        $service = serviceBlocking($provider, 30);
        bookLocally($provider, WINTER_MONDAY, '07:00', '08:00');

        DB::flushQueryLog();
        DB::enableQueryLog();
        $slots = app(FreeSlots::class)->slotsFor($provider, $service, [], CarbonImmutable::parse(WINTER_MONDAY));
        DB::disableQueryLog();

        return [$slots->count(), count(DB::getQueryLog())];
    };

    [$fewSlots, $fewQueries] = $countQueries(mondayProvider('09:00-11:30'));
    [$manySlots, $manyQueries] = $countQueries(mondayProvider('09:00-19:00'));

    expect($fewSlots)->toBe(5);
    expect($manySlots)->toBe(20);
    expect($manyQueries)->toBe($fewQueries);
    expect($fewQueries)->toBe(1);
});

test('the booking window days are exactly the days isWithinWindow accepts', function () {
    $this->travelTo(CarbonImmutable::parse(WINTER_MONDAY.' 10:00', 'Europe/Rome'));
    $freeSlots = app(FreeSlots::class);

    $window = $freeSlots->bookingWindow();

    // 12 January plus 60 calendar days is 13 March.
    expect($window)->toBe(['first_day' => '2026-01-12', 'last_day' => '2026-03-13']);
    expect($freeSlots->isWithinWindow(CarbonImmutable::parse($window['first_day'])))->toBeTrue();
    expect($freeSlots->isWithinWindow(CarbonImmutable::parse($window['last_day'])))->toBeTrue();
    expect($freeSlots->isWithinWindow(CarbonImmutable::parse($window['first_day'])->subDay()))->toBeFalse();
    expect($freeSlots->isWithinWindow(CarbonImmutable::parse($window['last_day'])->addDay()))->toBeFalse();
});
