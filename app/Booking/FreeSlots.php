<?php

namespace App\Booking;

use App\Models\Provider;
use App\Models\Service;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * The slots in which a service of a provider can be booked on a given day, in UTC.
 *
 * The list is only indicative: two customers can see the same free slot. Creating the
 * booking (Sprint 3) checks the slot again inside a transaction.
 */
final class FreeSlots
{
    public function __construct(
        private AvailabilityWindows $availabilityWindows,
        private BookingDuration $bookingDuration,
    ) {}

    /**
     * The bookable slots of the given local calendar day, ordered by start and without
     * duplicates. Each slot ends after the minutes the booking blocks.
     *
     * Wrong input always throws, even on a day without slots. A provider that is not
     * published, an inactive service or a day outside the booking window give no slots.
     * One query reads the bookings of the whole day, whatever the number of slots.
     *
     * @param  list<int>  $optionIds
     * @return Collection<int, TimeWindow>
     *
     * @throws ServiceNotOfProvider when the service belongs to another provider
     * @throws InvalidBookingOptions when an option is not an option of the service
     */
    public function slotsFor(Provider $provider, Service $service, array $optionIds, CarbonInterface $date): Collection
    {
        if ($service->provider_id !== $provider->id) {
            throw ServiceNotOfProvider::forProvider($service, $provider);
        }

        $minutes = $this->bookingDuration->minutesFor($service, $optionIds);

        if (! $provider->is_published || ! $service->is_active || ! $this->isWithinWindow($date)) {
            return new Collection;
        }

        $windows = $this->availabilityWindows->windowsFor($provider, $date);

        if ($windows->isEmpty()) {
            return new Collection;
        }

        $busy = $this->busyTimes($provider, $windows);
        $earliestStart = CarbonImmutable::now()->addMinutes(config()->integer('booking.minimum_notice_minutes'));

        return $windows
            ->flatMap(fn (TimeWindow $window) => $this->slotsInside($window, $minutes))
            ->reject(fn (TimeWindow $slot) => $slot->start->lessThan($earliestStart))
            ->reject(fn (TimeWindow $slot) => $busy->contains(fn (TimeWindow $booking) => $this->overlaps($slot, $booking)))
            // Overlapping windows (invalid but possible data) would give the same slot twice.
            ->unique(fn (TimeWindow $slot) => $slot->start->getTimestamp())
            ->sortBy(fn (TimeWindow $slot) => $slot->start->getTimestamp())
            ->values();
    }

    /**
     * Whether the local calendar day is between today and today plus the booking window,
     * both included. Only the date of $date is used, as in AvailabilityWindows; "today" is
     * the current date in the booking time zone.
     */
    public function isWithinWindow(CarbonInterface $date): bool
    {
        $today = CarbonImmutable::now(config()->string('booking.timezone'));
        $lastDay = $today->addDays(config()->integer('booking.max_days_ahead'));
        $day = $date->toDateString();

        // "Y-m-d" strings sort like the dates they represent.
        return $day >= $today->toDateString() && $day <= $lastDay->toDateString();
    }

    /**
     * The slots of a window: one every step from its start, as long as the whole booking fits.
     * Minutes are added to the UTC instant, never to the local time.
     *
     * @return list<TimeWindow>
     */
    private function slotsInside(TimeWindow $window, int $minutes): array
    {
        $step = config()->integer('booking.slot_step_minutes');
        $slots = [];

        for ($start = $window->start; $start->addMinutes($minutes)->lessThanOrEqualTo($window->end); $start = $start->addMinutes($step)) {
            $slots[] = new TimeWindow($start, $start->addMinutes($minutes));
        }

        return $slots;
    }

    /**
     * The pending and confirmed bookings of the provider that touch the day's windows,
     * read with one query.
     *
     * @param  Collection<int, TimeWindow>  $windows  ordered by start
     * @return Collection<int, TimeWindow>
     */
    private function busyTimes(Provider $provider, Collection $windows): Collection
    {
        $from = $windows->first()->start;
        $to = $windows->first()->end;

        foreach ($windows as $window) {
            $to = $window->end->greaterThan($to) ? $window->end : $to;
        }

        return $provider->bookings()
            ->occupying()
            ->overlapping($from, $to)
            ->get(['starts_at', 'ends_at'])
            ->toBase()
            // parse() accepts the cast Carbon date and also the plain string PHPStan sees for these columns.
            ->map(fn ($booking) => new TimeWindow(
                CarbonImmutable::parse($booking->starts_at, 'UTC')->utc(),
                CarbonImmutable::parse($booking->ends_at, 'UTC')->utc(),
            ));
    }

    /**
     * [a, b) and [c, d) overlap when a < d and b > c: back-to-back spans do not overlap.
     */
    private function overlaps(TimeWindow $first, TimeWindow $second): bool
    {
        return $first->start->lessThan($second->end) && $first->end->greaterThan($second->start);
    }
}
