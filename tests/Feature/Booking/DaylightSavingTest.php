<?php

use App\Booking\AvailabilityWindows;
use App\Booking\FreeSlots;
use App\Booking\TimeWindow;
use App\Enums\BookingStatus;
use App\Enums\Weekday;
use App\Models\Availability;
use App\Models\Booking;
use App\Models\Provider;
use App\Models\Service;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

// The two 2026 days on which the clock changes in Italy:
// - Sunday 29 March: at 02:00 local the clock jumps to 03:00 (UTC+1 becomes UTC+2);
// - Sunday 25 October: at 03:00 local the clock goes back to 02:00 (UTC+2 becomes UTC+1).
// Expected values are UTC instants, written as "Y-m-d H:i".
const SPRING_CHANGE_DAY = '2026-03-29';
const AUTUMN_CHANGE_DAY = '2026-10-25';

beforeEach(function () {
    // A default "now" inside the booking window of the spring change; tests about the
    // autumn change or the window set their own.
    $this->travelTo(CarbonImmutable::parse('2026-03-01 08:00', 'UTC'));
});

/**
 * A published provider with the given local windows: [weekday, "HH:MM", "HH:MM"].
 *
 * @param  array{Weekday, string, string}  ...$windows
 */
function dstProvider(array ...$windows): Provider
{
    $provider = Provider::factory()->published()->create();

    foreach ($windows as [$weekday, $start, $end]) {
        Availability::factory()->for($provider)->create([
            'weekday' => $weekday,
            'start_time' => "{$start}:00",
            'end_time' => "{$end}:00",
        ]);
    }

    return $provider->load('availabilities');
}

/**
 * The windows of a local day as [start, end] UTC strings.
 *
 * @return list<array{string, string}>
 */
function dstWindows(Provider $provider, string $day): array
{
    return (new AvailabilityWindows)
        ->windowsFor($provider, CarbonImmutable::parse($day))
        ->map(fn (TimeWindow $window) => [$window->start->format('Y-m-d H:i'), $window->end->format('Y-m-d H:i')])
        ->all();
}

/**
 * The free slots of a local day for a 60-minute service, with the default 30-minute step.
 *
 * @return Collection<int, TimeWindow>
 */
function dstSlots(Provider $provider, string $day): Collection
{
    $service = Service::factory()->for($provider)->create(['duration_min' => 60, 'duration_max' => 60]);

    return app(FreeSlots::class)->slotsFor($provider, $service, [], CarbonImmutable::parse($day));
}

/**
 * The start of each slot as a UTC "Y-m-d H:i" string.
 *
 * @param  Collection<int, TimeWindow>  $slots
 * @return list<string>
 */
function dstStarts(Collection $slots): array
{
    return $slots->map(fn (TimeWindow $slot) => $slot->start->format('Y-m-d H:i'))->all();
}

/**
 * The start of each slot as a local "H:i" string.
 *
 * @param  Collection<int, TimeWindow>  $slots
 * @return list<string>
 */
function dstLocalStarts(Collection $slots): array
{
    return $slots->map(fn (TimeWindow $slot) => $slot->start->setTimezone(config()->string('booking.timezone'))->format('H:i'))->all();
}

/**
 * A confirmed booking of the provider between two UTC instants ("Y-m-d H:i").
 */
function dstConfirmedBooking(Provider $provider, string $startsAt, string $endsAt): void
{
    Booking::factory()->for($provider)->for(Service::factory()->for($provider))->create([
        'starts_at' => CarbonImmutable::parse($startsAt, 'UTC'),
        'ends_at' => CarbonImmutable::parse($endsAt, 'UTC'),
        'status' => BookingStatus::Confirmed,
    ]);
}

test('the change days are Sundays, between a Saturday and a Monday', function () {
    expect(CarbonImmutable::parse('2026-03-28')->dayOfWeekIso)->toBe(Weekday::Saturday->value);
    expect(CarbonImmutable::parse(SPRING_CHANGE_DAY)->dayOfWeekIso)->toBe(Weekday::Sunday->value);
    expect(CarbonImmutable::parse('2026-03-30')->dayOfWeekIso)->toBe(Weekday::Monday->value);
    expect(CarbonImmutable::parse('2026-10-24')->dayOfWeekIso)->toBe(Weekday::Saturday->value);
    expect(CarbonImmutable::parse(AUTUMN_CHANGE_DAY)->dayOfWeekIso)->toBe(Weekday::Sunday->value);
    expect(CarbonImmutable::parse('2026-10-26')->dayOfWeekIso)->toBe(Weekday::Monday->value);
    expect(Weekday::Sunday->value)->toBe(7);
});

test('on the spring change day, a 09:00-13:00 window moves from UTC+1 to UTC+2', function () {
    $provider = dstProvider(
        [Weekday::Saturday, '09:00', '13:00'],
        [Weekday::Sunday, '09:00', '13:00'],
        [Weekday::Monday, '09:00', '13:00'],
    );

    // Saturday is still winter time (UTC+1): 09:00 local is 08:00 UTC.
    expect(dstWindows($provider, '2026-03-28'))->toBe([['2026-03-28 08:00', '2026-03-28 12:00']]);
    // From 03:00 on Sunday it is summer time (UTC+2): 09:00 local is 07:00 UTC.
    expect(dstWindows($provider, SPRING_CHANGE_DAY))->toBe([['2026-03-29 07:00', '2026-03-29 11:00']]);
    expect(dstWindows($provider, '2026-03-30'))->toBe([['2026-03-30 07:00', '2026-03-30 11:00']]);
});

test('on the autumn change day, a 09:00-13:00 window moves from UTC+2 to UTC+1', function () {
    $provider = dstProvider(
        [Weekday::Saturday, '09:00', '13:00'],
        [Weekday::Sunday, '09:00', '13:00'],
        [Weekday::Monday, '09:00', '13:00'],
    );

    // Saturday is still summer time (UTC+2): 09:00 local is 07:00 UTC.
    expect(dstWindows($provider, '2026-10-24'))->toBe([['2026-10-24 07:00', '2026-10-24 11:00']]);
    // From 03:00 (back to 02:00) on Sunday it is winter time (UTC+1): 09:00 local is 08:00 UTC.
    expect(dstWindows($provider, AUTUMN_CHANGE_DAY))->toBe([['2026-10-25 08:00', '2026-10-25 12:00']]);
    expect(dstWindows($provider, '2026-10-26'))->toBe([['2026-10-26 08:00', '2026-10-26 12:00']]);
});

test('on the spring change day, a 01:00-05:00 window lasts three real hours', function () {
    $provider = dstProvider([Weekday::Sunday, '01:00', '05:00']);

    // 01:00 is still UTC+1 (00:00 UTC); 05:00 is already UTC+2 (03:00 UTC).
    // The hour 02:00-03:00 does not exist, so four local hours are three real ones.
    $windows = (new AvailabilityWindows)->windowsFor($provider, CarbonImmutable::parse(SPRING_CHANGE_DAY));

    expect(dstWindows($provider, SPRING_CHANGE_DAY))->toBe([['2026-03-29 00:00', '2026-03-29 03:00']]);
    expect($windows->first()->start->diffInMinutes($windows->first()->end))->toEqual(180);
});

test('on the autumn change day, a 01:00-05:00 window lasts five real hours and starts the day before in UTC', function () {
    $provider = dstProvider([Weekday::Sunday, '01:00', '05:00']);

    // 01:00 is still UTC+2 (23:00 UTC of Saturday 24); 05:00 is already UTC+1 (04:00 UTC).
    // The hour 02:00-03:00 happens twice, so four local hours are five real ones.
    $window = (new AvailabilityWindows)->windowsFor($provider, CarbonImmutable::parse(AUTUMN_CHANGE_DAY))->first();

    expect(dstWindows($provider, AUTUMN_CHANGE_DAY))->toBe([['2026-10-24 23:00', '2026-10-25 04:00']]);
    expect($window->start->toDateString())->toBe('2026-10-24');
    expect($window->start->diffInMinutes($window->end))->toEqual(300);
});

test('on the spring change day, the slots of a 01:00-05:00 window skip the missing hour', function () {
    $provider = dstProvider([Weekday::Sunday, '01:00', '05:00']);

    // The window is 00:00-03:00 UTC: 60-minute slots every 30 minutes, the last one ending at 03:00.
    $slots = dstSlots($provider, SPRING_CHANGE_DAY);

    expect(dstStarts($slots))->toBe([
        '2026-03-29 00:00', '2026-03-29 00:30', '2026-03-29 01:00', '2026-03-29 01:30', '2026-03-29 02:00',
    ]);
    // In local time they are 01:00, 01:30, 03:00, 03:30 and 04:00: no slot in the missing 02:xx hour.
    expect(dstLocalStarts($slots))->toBe(['01:00', '01:30', '03:00', '03:30', '04:00']);
    expect(collect(dstLocalStarts($slots))->filter(fn (string $time) => str_starts_with($time, '02:')))->toBeEmpty();
});

test('on the autumn change day, the slots of a 01:00-05:00 window include the repeated hour twice', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-01 08:00', 'UTC'));
    $provider = dstProvider([Weekday::Sunday, '01:00', '05:00']);

    // The window is 23:00 UTC of the 24th to 04:00 UTC of the 25th: 9 starts every 30 minutes.
    $slots = dstSlots($provider, AUTUMN_CHANGE_DAY);

    expect(dstStarts($slots))->toBe([
        '2026-10-24 23:00', '2026-10-24 23:30', '2026-10-25 00:00', '2026-10-25 00:30', '2026-10-25 01:00',
        '2026-10-25 01:30', '2026-10-25 02:00', '2026-10-25 02:30', '2026-10-25 03:00',
    ]);
    expect($slots->map(fn (TimeWindow $slot) => $slot->start->getTimestamp())->unique())->toHaveCount(9);
    // 00:00 and 01:00 UTC are both 02:00 local (first in summer time, then in winter time).
    $localStarts = collect(dstLocalStarts($slots));
    expect($localStarts->all())->toBe(['01:00', '01:30', '02:00', '02:30', '02:00', '02:30', '03:00', '03:30', '04:00']);
    expect($localStarts->filter(fn (string $time) => $time === '02:00'))->toHaveCount(2);
});

test('on the spring change day, a booking from 09:00 to 10:00 local blocks the first slots', function () {
    $provider = dstProvider([Weekday::Sunday, '09:00', '13:00']);
    // 09:00-10:00 local in summer time is 07:00-08:00 UTC.
    dstConfirmedBooking($provider, '2026-03-29 07:00', '2026-03-29 08:00');

    // The window is 07:00-11:00 UTC; the slots at 07:00 and 07:30 UTC overlap the booking.
    expect(dstStarts(dstSlots($provider, SPRING_CHANGE_DAY)))->toBe([
        '2026-03-29 08:00', '2026-03-29 08:30', '2026-03-29 09:00', '2026-03-29 09:30', '2026-03-29 10:00',
    ]);
});

test('on the autumn change day, a booking from 09:00 to 10:00 local blocks the first slots', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-01 08:00', 'UTC'));
    $provider = dstProvider([Weekday::Sunday, '09:00', '13:00']);
    // 09:00-10:00 local in winter time is 08:00-09:00 UTC.
    dstConfirmedBooking($provider, '2026-10-25 08:00', '2026-10-25 09:00');

    // The window is 08:00-12:00 UTC; the slots at 08:00 and 08:30 UTC overlap the booking.
    expect(dstStarts(dstSlots($provider, AUTUMN_CHANGE_DAY)))->toBe([
        '2026-10-25 09:00', '2026-10-25 09:30', '2026-10-25 10:00', '2026-10-25 10:30', '2026-10-25 11:00',
    ]);
});

test('on the autumn change day, the requested local day keeps Saturday and Sunday apart', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-01 08:00', 'UTC'));
    $provider = dstProvider(
        [Weekday::Saturday, '09:00', '13:00'],
        [Weekday::Sunday, '01:00', '05:00'],
    );

    // Saturday 09:00-13:00 local is 07:00-11:00 UTC. Sunday's first slot starts on the 24th
    // at 23:00 UTC, but it belongs to Sunday: asking for Saturday does not show it.
    $saturday = dstStarts(dstSlots($provider, '2026-10-24'));
    $sunday = dstStarts(dstSlots($provider, AUTUMN_CHANGE_DAY));

    expect($saturday)->toBe([
        '2026-10-24 07:00', '2026-10-24 07:30', '2026-10-24 08:00', '2026-10-24 08:30',
        '2026-10-24 09:00', '2026-10-24 09:30', '2026-10-24 10:00',
    ]);
    expect($saturday)->not->toContain('2026-10-24 23:00');
    expect($sunday[0])->toBe('2026-10-24 23:00');
});

test('on the spring change day, just after midnight local, today is already the Sunday', function () {
    // 23:30 UTC on Saturday 28 March is 00:30 on Sunday 29 in Italy (still UTC+1).
    $this->travelTo(CarbonImmutable::parse('2026-03-28 23:30', 'UTC'));
    $freeSlots = app(FreeSlots::class);

    expect($freeSlots->isWithinWindow(CarbonImmutable::parse(SPRING_CHANGE_DAY)))->toBeTrue();
    expect($freeSlots->isWithinWindow(CarbonImmutable::parse('2026-03-28')))->toBeFalse();
});

test('across the spring change, the booking window counts 60 calendar days, not 60 periods of 24 hours', function () {
    // 22:30 UTC on 1 February is 23:30 on 1 February in Italy: today is 1 February.
    $this->travelTo(CarbonImmutable::parse('2026-02-01 22:30', 'UTC'));
    config()->set('booking.max_days_ahead', 60);
    $freeSlots = app(FreeSlots::class);

    // 1 February plus 60 calendar days is 2 April (27 days to the end of February, 31 of March, 2 of April).
    // Counting 60 periods of 24 hours from 23:30 would cross the lost hour of 29 March and land on
    // 3 April at 00:30 local time, so the last day would be wrong.
    expect($freeSlots->isWithinWindow(CarbonImmutable::parse('2026-02-01')))->toBeTrue();
    expect($freeSlots->isWithinWindow(CarbonImmutable::parse('2026-01-31')))->toBeFalse();
    expect($freeSlots->isWithinWindow(CarbonImmutable::parse('2026-04-02')))->toBeTrue();
    expect($freeSlots->isWithinWindow(CarbonImmutable::parse('2026-04-03')))->toBeFalse();
});

test('on the autumn change day, just after midnight local, today is already the Sunday', function () {
    // 22:30 UTC on Saturday 24 October is 00:30 on Sunday 25 in Italy (still UTC+2).
    $this->travelTo(CarbonImmutable::parse('2026-10-24 22:30', 'UTC'));
    $freeSlots = app(FreeSlots::class);

    expect($freeSlots->isWithinWindow(CarbonImmutable::parse(AUTUMN_CHANGE_DAY)))->toBeTrue();
    expect($freeSlots->isWithinWindow(CarbonImmutable::parse('2026-10-24')))->toBeFalse();
});
