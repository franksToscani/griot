<?php

namespace App\Booking;

/**
 * Who cancelled a booking, and how. Set by the code that cancels (ManageBooking, CancelBooking),
 * never by the rules: BookingTransitions does not read it. Null on a booking that is not
 * cancelled, and on the ones cancelled before the column existed.
 */
enum CancelledBy: string
{
    // The provider rejected a pending request.
    case ProviderReject = 'provider_reject';
    // The provider cancelled a confirmed booking.
    case ProviderCancel = 'provider_cancel';
    // The customer cancelled from the private page.
    case Customer = 'customer';
}
