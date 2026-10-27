<?php

namespace App\Booking;

use App\Enums\BookingStatus;
use App\Models\Booking;
use Illuminate\Support\Facades\DB;

/**
 * Cancels a booking on behalf of the customer, up to the cancellation notice before it starts.
 */
final class CancelBooking
{
    public function __construct(
        private BookingTransitions $transitions,
    ) {}

    /**
     * Whether the customer can still cancel: the booking is pending or confirmed, and now is
     * before the limit or exactly on it. The rule is BookingTransitions'; it does not query the
     * database.
     */
    public function canCancel(Booking $booking): bool
    {
        return $this->transitions->canCancelAsCustomer($booking);
    }

    /**
     * Cancel the booking. Returns false, without writing, if it was already cancelled.
     *
     * @throws CancellationNotAllowed when the notice has passed or the booking has started
     */
    public function cancel(Booking $booking): bool
    {
        return DB::transaction(function () use ($booking): bool {
            // This MUST be the first statement, and from here on only $locked is used, never the
            // model received: it may be old. The provider will be able to confirm or cancel the
            // same booking at the same moment (Sprint 6); the lock makes the two wait for each
            // other, and the decision is taken on the row as it is now.
            $locked = Booking::query()->whereKey($booking->id)->lockForUpdate()->firstOrFail();

            if ($locked->status === BookingStatus::Cancelled) {
                return false;
            }

            if (! $this->canCancel($locked)) {
                throw CancellationNotAllowed::for($locked);
            }

            $locked->status = BookingStatus::Cancelled;
            $locked->cancelled_by = CancelledBy::Customer;
            $locked->save();

            return true;
        });
    }
}
