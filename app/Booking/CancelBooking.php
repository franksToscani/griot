<?php

namespace App\Booking;

use App\Enums\BookingStatus;
use App\Models\Booking;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Cancels a booking on behalf of the customer, up to the cancellation notice before it starts.
 */
final class CancelBooking
{
    /**
     * Whether the customer can still cancel: the booking is pending or confirmed, and now is
     * before the limit or exactly on it. It does not query the database.
     */
    public function canCancel(Booking $booking): bool
    {
        if (! in_array($booking->status, [BookingStatus::Pending, BookingStatus::Confirmed], true)) {
            return false;
        }

        // Minutes on the UTC instant, not "one day before" on the local calendar: the same number
        // of minutes in winter, in summer and across a change of daylight saving time.
        $limit = $booking->starts_at->utc()->subMinutes(config()->integer('booking.cancellation_notice_minutes'));

        return CarbonImmutable::now()->lessThanOrEqualTo($limit);
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
            $locked->save();

            return true;
        });
    }
}
