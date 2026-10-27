<?php

namespace App\Booking;

use App\Enums\BookingStatus;
use App\Models\Booking;
use Carbon\CarbonImmutable;

/**
 * The only place that knows how the status of a booking can change, and who can change it:
 *
 * - pending → confirmed: the provider, only before the start (at the exact start: refused);
 * - pending → cancelled (a rejection): the provider, always;
 * - confirmed → cancelled: the provider, always;
 * - pending or confirmed → cancelled: the customer, up to the cancellation notice before the
 *   start, the limit included;
 * - anything else is refused: a cancelled booking never changes again, a confirmed one never goes
 *   back to pending.
 *
 * Every method reads the booking it receives and the clock, never the database: the callers pass
 * a row they have just read again under a lock.
 */
final class BookingTransitions
{
    public function canConfirm(Booking $booking): bool
    {
        return $this->confirmRefusal($booking) === null;
    }

    public function canReject(Booking $booking): bool
    {
        return $this->rejectRefusal($booking) === null;
    }

    public function canCancelAsProvider(Booking $booking): bool
    {
        return $this->providerCancelRefusal($booking) === null;
    }

    public function canCancelAsCustomer(Booking $booking): bool
    {
        return $this->customerCancelRefusal($booking) === null;
    }

    /**
     * Why the provider cannot confirm, or null if it can.
     *
     * @return 'already_cancelled'|'not_pending'|'already_started'|null
     */
    public function confirmRefusal(Booking $booking): ?string
    {
        return match (true) {
            $booking->status === BookingStatus::Cancelled => 'already_cancelled',
            $booking->status !== BookingStatus::Pending => 'not_pending',
            // Strictly before: at the exact start the slot has begun.
            ! CarbonImmutable::now()->lessThan($booking->starts_at) => 'already_started',
            default => null,
        };
    }

    /**
     * Why the provider cannot reject, or null if it can: a pending booking, even a past one.
     *
     * @return 'already_cancelled'|'not_pending'|null
     */
    public function rejectRefusal(Booking $booking): ?string
    {
        return match (true) {
            $booking->status === BookingStatus::Cancelled => 'already_cancelled',
            $booking->status !== BookingStatus::Pending => 'not_pending',
            default => null,
        };
    }

    /**
     * Why the provider cannot cancel, or null if it can: a confirmed booking, even a past one.
     *
     * @return 'already_cancelled'|'not_confirmed'|null
     */
    public function providerCancelRefusal(Booking $booking): ?string
    {
        return match (true) {
            $booking->status === BookingStatus::Cancelled => 'already_cancelled',
            $booking->status !== BookingStatus::Confirmed => 'not_confirmed',
            default => null,
        };
    }

    /**
     * Why the customer cannot cancel, or null if it can.
     *
     * @return 'already_cancelled'|'too_late'|null
     */
    public function customerCancelRefusal(Booking $booking): ?string
    {
        if ($booking->status === BookingStatus::Cancelled) {
            return 'already_cancelled';
        }

        // Minutes on the UTC instant, not "one day before" on the local calendar: the same number
        // of minutes in winter, in summer and across a change of daylight saving time.
        $limit = $booking->starts_at->utc()->subMinutes(config()->integer('booking.cancellation_notice_minutes'));

        // Now before the limit or exactly on it.
        return CarbonImmutable::now()->lessThanOrEqualTo($limit) ? null : 'too_late';
    }
}
