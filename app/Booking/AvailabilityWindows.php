<?php

namespace App\Booking;

use App\Enums\Weekday;
use App\Models\Availability;
use App\Models\Provider;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * The time windows in which a provider works on a given day, in UTC.
 *
 * Availabilities are local wall-clock times in the booking time zone; this class is the
 * one place where they are converted to UTC. A window that crosses midnight (for example
 * 22:00-02:00) is not supported: its end comes before its start, so it is ignored below.
 */
final class AvailabilityWindows
{
    /**
     * The windows of the given local calendar day, ordered by start.
     *
     * Only the calendar date of $date is used, never its time or time zone: the caller
     * passes the provider's local day (a Saturday 23:30 UTC is still that Saturday here).
     * If the availabilities are already loaded, no query runs.
     *
     * @return Collection<int, TimeWindow>
     */
    public function windowsFor(Provider $provider, CarbonInterface $date): Collection
    {
        $timezone = config()->string('booking.timezone');
        $day = $date->toDateString();
        $weekday = Weekday::from($date->dayOfWeekIso);

        return $provider->availabilities
            ->toBase()
            ->where('weekday', $weekday)
            ->map(fn (Availability $availability) => new TimeWindow(
                // Date and time are read in the local zone, then moved to UTC: no hours added by hand.
                CarbonImmutable::parse("{$day} {$availability->start_time}", $timezone)->utc(),
                CarbonImmutable::parse("{$day} {$availability->end_time}", $timezone)->utc(),
            ))
            // An end at or before the start is invalid data: it is skipped so that the public page
            // keeps working. Saving such a window will be prevented by the validation in Sprint 5.
            ->filter(fn (TimeWindow $window) => $window->end->greaterThan($window->start))
            ->sortBy(fn (TimeWindow $window) => $window->start->getTimestamp())
            ->values();
    }
}
