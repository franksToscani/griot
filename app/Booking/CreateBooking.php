<?php

namespace App\Booking;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\Provider;
use App\Models\Service;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Creates a pending booking, only if the chosen start is still a free slot.
 *
 * What can be booked is decided in one place, FreeSlots: this class asks it again, inside a
 * transaction, and accepts only a start that is exactly one of the slots it returns.
 */
final class CreateBooking
{
    public function __construct(
        private FreeSlots $freeSlots,
    ) {}

    /**
     * @param  list<int>  $optionIds
     *
     * @throws SlotNotAvailable when the start is not a free slot (taken, past, off the grid...)
     * @throws ServiceNotOfProvider when the service belongs to another provider
     * @throws InvalidBookingOptions when an option is not an option of the service
     */
    public function create(Provider $provider, Service $service, array $optionIds, CarbonInterface $startsAt, CustomerDetails $customer): Booking
    {
        $start = CarbonImmutable::instance($startsAt)->utc();
        // FreeSlots works on the provider's local calendar day.
        $day = $start->setTimezone(config()->string('booking.timezone'));

        // Any exception inside the closure rolls the transaction back: nothing is saved.
        return DB::transaction(function () use ($provider, $service, $optionIds, $start, $day, $customer): Booking {
            // This lock MUST be the first statement of the transaction. Two bookings for the same
            // provider wait for each other here. On MySQL (REPEATABLE READ) a transaction reads
            // from a snapshot taken at its first plain read: if the bookings were read before the
            // lock, the snapshot could be older than the booking the other transaction has just
            // committed, and the same slot would look free. Reading the slots after the lock
            // sees it. (SQLite, used by the tests, ignores the lock and runs one write at a time.)
            $lockedProvider = Provider::query()->whereKey($provider->id)->lockForUpdate()->firstOrFail();

            $slot = $this->freeSlots
                ->slotsFor($lockedProvider, $service, $optionIds, $day)
                ->first(fn (TimeWindow $slot) => $slot->start->getTimestamp() === $start->getTimestamp());

            if ($slot === null) {
                throw SlotNotAvailable::at($lockedProvider, $start);
            }

            // Through the relation: provider_id is set by the code, never by the input.
            $booking = $lockedProvider->bookings()->make([
                'service_id' => $service->id,
                'customer_name' => $customer->name,
                'customer_phone' => $customer->phone,
                'customer_email' => $customer->email,
                'notes' => $customer->notes,
                'starts_at' => $slot->start,
                // The slot already ends after the minutes computed by BookingDuration.
                'ends_at' => $slot->end,
                'status' => BookingStatus::Pending,
            ]);
            $booking->public_token = Str::random(48);
            $booking->save();

            $booking->options()->attach(array_values(array_unique($optionIds)));

            return $booking;
        });
    }
}
