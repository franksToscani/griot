<?php

namespace App\Booking;

use App\Enums\BookingStatus;
use App\Models\Booking;

/**
 * The provider's answer, as the customer is told about it. The value is the end of the
 * translation keys (booking.notice.subject_confirmed, provider.bookings.whatsapp_text_rejected…).
 */
enum BookingOutcome: string
{
    case Confirmed = 'confirmed';
    // A pending request the provider did not accept.
    case Rejected = 'rejected';
    // A confirmed booking the provider cancelled.
    case Cancelled = 'cancelled';

    /**
     * The provider's answer that the booking shows now; null for a pending booking, one cancelled
     * by the customer, and one cancelled before cancelled_by existed (who cancelled is unknown).
     */
    public static function of(Booking $booking): ?self
    {
        return match ($booking->status) {
            BookingStatus::Confirmed => self::Confirmed,
            BookingStatus::Cancelled => match ($booking->cancelled_by) {
                CancelledBy::ProviderReject => self::Rejected,
                CancelledBy::ProviderCancel => self::Cancelled,
                CancelledBy::Customer, null => null,
            },
            BookingStatus::Pending => null,
        };
    }
}
