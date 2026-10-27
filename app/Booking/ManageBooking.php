<?php

namespace App\Booking;

use App\Enums\BookingStatus;
use App\Models\Booking;
use Closure;
use Illuminate\Support\Facades\DB;

/**
 * The provider's answers to a booking: confirm, reject (a pending one) and cancel (a confirmed
 * one). The rules are BookingTransitions'; this class reads the row again under a lock and writes.
 * Nothing is idempotent: asking again for a change already made is refused with its reason, and
 * nothing is written. A cancelled booking frees its slot at once: FreeSlots and CreateBooking only
 * count pending and confirmed bookings.
 */
final class ManageBooking
{
    public function __construct(
        private BookingTransitions $transitions,
    ) {}

    /**
     * Confirm a pending booking that has not started yet. The cancellation reason is not touched.
     *
     * @throws TransitionNotAllowed
     */
    public function confirm(Booking $booking): Booking
    {
        return $this->change($booking, 'confirm', $this->transitions->confirmRefusal(...), function (Booking $locked): void {
            $locked->status = BookingStatus::Confirmed;
        });
    }

    /**
     * Reject a pending booking, with an optional message for the customer.
     *
     * @throws TransitionNotAllowed
     */
    public function reject(Booking $booking, ?string $reason): Booking
    {
        return $this->change($booking, 'reject', $this->transitions->rejectRefusal(...), function (Booking $locked) use ($reason): void {
            $locked->status = BookingStatus::Cancelled;
            $locked->cancellation_reason = $this->cleanReason($reason);
        });
    }

    /**
     * Cancel a confirmed booking, with an optional message for the customer.
     *
     * @throws TransitionNotAllowed
     */
    public function cancel(Booking $booking, ?string $reason): Booking
    {
        return $this->change($booking, 'cancel', $this->transitions->providerCancelRefusal(...), function (Booking $locked) use ($reason): void {
            $locked->status = BookingStatus::Cancelled;
            $locked->cancellation_reason = $this->cleanReason($reason);
        });
    }

    /**
     * @param  'confirm'|'reject'|'cancel'  $action
     * @param  Closure(Booking): ('not_pending'|'not_confirmed'|'already_started'|'already_cancelled'|null)  $refusal
     * @param  Closure(Booking): void  $apply
     */
    private function change(Booking $booking, string $action, Closure $refusal, Closure $apply): Booking
    {
        return DB::transaction(function () use ($booking, $action, $refusal, $apply): Booking {
            // This MUST be the first statement, and from here on only $locked is used, never the
            // model received: it may be old. The customer can cancel the same booking at the same
            // moment (CancelBooking locks the same row); the two wait for each other, and the
            // decision is taken on the row as it is now.
            $locked = Booking::query()->whereKey($booking->id)->lockForUpdate()->firstOrFail();

            $reason = $refusal($locked);

            if ($reason !== null) {
                throw TransitionNotAllowed::because($locked, $action, $reason);
            }

            // Not fillable: assigned one by one.
            $apply($locked);
            $locked->save();

            return $locked;
        });
    }

    /**
     * Plain text, trimmed; empty or only spaces is no message.
     */
    private function cleanReason(?string $reason): ?string
    {
        $reason = trim((string) $reason);

        return $reason === '' ? null : $reason;
    }
}
