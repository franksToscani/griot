<?php

use App\Booking\AvailabilityWindows;
use App\Booking\TimeWindow;
use App\Enums\Weekday;
use App\Models\Availability;
use App\Models\Provider;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

// Real 2026 dates: 12 January and 13 July are Mondays, 17 October is a Saturday, 18 a Sunday, 19 a Monday.

/**
 * The windows as "HH:MM-HH:MM" strings in UTC, easy to compare.
 *
 * @return list<string>
 */
function windowsAsText(Provider $provider, string $date): array
{
    return (new AvailabilityWindows)
        ->windowsFor($provider, CarbonImmutable::parse($date))
        ->map(fn (TimeWindow $window) => $window->start->format('H:i').'-'.$window->end->format('H:i'))
        ->all();
}

function addAvailability(Provider $provider, Weekday $weekday, string $start, string $end): void
{
    Availability::factory()->for($provider)->create([
        'weekday' => $weekday,
        'start_time' => $start,
        'end_time' => $end,
    ]);
}

test('a day without availabilities has no windows', function () {
    $provider = Provider::factory()->create();
    addAvailability($provider, Weekday::Tuesday, '09:00:00', '13:00:00');

    $windows = (new AvailabilityWindows)->windowsFor($provider, CarbonImmutable::parse('2026-01-12'));

    expect($windows)->toBeEmpty();
});

test('a day with one availability has one window', function () {
    $provider = Provider::factory()->create();
    addAvailability($provider, Weekday::Monday, '09:00:00', '13:00:00');

    expect(windowsAsText($provider, '2026-01-12'))->toBe(['08:00-12:00']);
});

test('several windows of the same day are ordered by start, whatever the insertion order', function () {
    $provider = Provider::factory()->create();
    addAvailability($provider, Weekday::Monday, '15:00:00', '19:00:00');
    addAvailability($provider, Weekday::Monday, '09:00:00', '13:00:00');

    expect(windowsAsText($provider, '2026-01-12'))->toBe(['08:00-12:00', '14:00-18:00']);
});

test('availabilities of other weekdays are ignored', function () {
    $provider = Provider::factory()->create();
    addAvailability($provider, Weekday::Monday, '09:00:00', '13:00:00');
    addAvailability($provider, Weekday::Tuesday, '10:00:00', '12:00:00');
    addAvailability($provider, Weekday::Sunday, '10:00:00', '12:00:00');

    expect(windowsAsText($provider, '2026-01-12'))->toBe(['08:00-12:00']);
});

test('availabilities of other providers are ignored', function () {
    $provider = Provider::factory()->create();
    $other = Provider::factory()->create();
    addAvailability($provider, Weekday::Monday, '09:00:00', '13:00:00');
    addAvailability($other, Weekday::Monday, '15:00:00', '19:00:00');

    expect(windowsAsText($provider, '2026-01-12'))->toBe(['08:00-12:00']);
});

test('Sunday is weekday 7, between Saturday and Monday', function () {
    $provider = Provider::factory()->create();
    addAvailability($provider, Weekday::Saturday, '09:00:00', '10:00:00');
    addAvailability($provider, Weekday::Sunday, '11:00:00', '12:00:00');
    addAvailability($provider, Weekday::Monday, '13:00:00', '14:00:00');

    // October is still summer time in Italy: local time is UTC+2.
    expect(windowsAsText($provider, '2026-10-17'))->toBe(['07:00-08:00']);
    expect(windowsAsText($provider, '2026-10-18'))->toBe(['09:00-10:00']);
    expect(windowsAsText($provider, '2026-10-19'))->toBe(['11:00-12:00']);
});

test('local times are converted to UTC in winter and in summer', function () {
    $provider = Provider::factory()->create();
    addAvailability($provider, Weekday::Monday, '09:00:00', '13:00:00');

    // Winter: Italy is UTC+1, so 09:00 local is 08:00 UTC.
    expect(windowsAsText($provider, '2026-01-12'))->toBe(['08:00-12:00']);
    // Summer: Italy is UTC+2, so 09:00 local is 07:00 UTC.
    expect(windowsAsText($provider, '2026-07-13'))->toBe(['07:00-11:00']);
});

test('only the calendar date of the given day is used, not its time or time zone', function () {
    $provider = Provider::factory()->create();
    addAvailability($provider, Weekday::Saturday, '09:00:00', '13:00:00');
    addAvailability($provider, Weekday::Sunday, '10:00:00', '12:00:00');

    // Saturday 23:30 UTC is already Sunday in Italy, but the caller asked for Saturday.
    $lateSaturday = CarbonImmutable::parse('2026-10-17 23:30:00', 'UTC');
    $windows = (new AvailabilityWindows)->windowsFor($provider, $lateSaturday);

    expect($windows)->toHaveCount(1);
    expect($windows->first()->start->toIso8601String())->toBe('2026-10-17T07:00:00+00:00');
});

test('a window that ends at or before its start is ignored', function () {
    $provider = Provider::factory()->create();
    addAvailability($provider, Weekday::Monday, '09:00:00', '13:00:00');
    addAvailability($provider, Weekday::Monday, '15:00:00', '15:00:00');
    addAvailability($provider, Weekday::Monday, '18:00:00', '16:00:00');
    // Crossing midnight is not supported: it is ignored the same way.
    addAvailability($provider, Weekday::Monday, '22:00:00', '02:00:00');

    expect(windowsAsText($provider, '2026-01-12'))->toBe(['08:00-12:00']);
});

test('the windows are immutable and in UTC', function () {
    $provider = Provider::factory()->create();
    addAvailability($provider, Weekday::Monday, '09:00:00', '13:00:00');

    $window = (new AvailabilityWindows)->windowsFor($provider, CarbonImmutable::parse('2026-01-12'))->first();

    expect($window)->toBeInstanceOf(TimeWindow::class);
    expect($window->start)->toBeInstanceOf(CarbonImmutable::class);
    expect($window->end)->toBeInstanceOf(CarbonImmutable::class);
    expect($window->start->timezoneName)->toBe('UTC');
    expect($window->end->timezoneName)->toBe('UTC');

    // Changing a date gives a new object and leaves the window as it was.
    $window->start->addHour();
    expect($window->start->format('H:i'))->toBe('08:00');

    expect(fn () => $window->start = CarbonImmutable::now())->toThrow(Error::class);
});

test('the conversion follows the time zone in the booking configuration', function () {
    $provider = Provider::factory()->create();
    addAvailability($provider, Weekday::Monday, '09:00:00', '13:00:00');

    config(['booking.timezone' => 'America/New_York']);

    // In January New York is UTC-5, so 09:00 local is 14:00 UTC.
    expect(windowsAsText($provider, '2026-01-12'))->toBe(['14:00-18:00']);
});

test('no query runs when the availabilities are already loaded', function () {
    $provider = Provider::factory()->create();
    addAvailability($provider, Weekday::Monday, '09:00:00', '13:00:00');
    $provider = Provider::query()->with('availabilities')->findOrFail($provider->id);

    DB::flushQueryLog();
    DB::enableQueryLog();
    $windows = (new AvailabilityWindows)->windowsFor($provider, CarbonImmutable::parse('2026-01-12'));
    DB::disableQueryLog();

    expect($windows)->toHaveCount(1);
    expect(DB::getQueryLog())->toBe([]);
});
