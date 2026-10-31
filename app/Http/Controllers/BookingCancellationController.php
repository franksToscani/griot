<?php

namespace App\Http\Controllers;

use App\Booking\CancelBooking;
use App\Booking\CancellationNotAllowed;
use App\Models\Booking;
use App\Notifications\CustomerCancelledNotification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * The customer cancels a booking from its private page.
 */
class BookingCancellationController extends Controller
{
    /**
     * Handle the incoming request. The booking is found by public_token (route binding), never by
     * id. The request has no fields: whatever is sent in the body is ignored. The provider being
     * unpublished or the service inactive does not matter: the customer can still cancel.
     */
    public function __invoke(Booking $booking, CancelBooking $cancelBooking): RedirectResponse
    {
        try {
            $cancelled = $cancelBooking->cancel($booking);
        } catch (CancellationNotAllowed) {
            // redirectTo(): back to the private page, not to the "previous" address, which here is
            // not reliable: the page sends no Referer (Referrer-Policy: no-referrer) and an Inertia
            // visit does not update the previous address in the session, so it could be the
            // provider page.
            throw ValidationException::withMessages(['cancel' => __('booking.cancel.too_late')])
                ->redirectTo(route('bookings.show', ['booking' => $booking->public_token]));
        }

        // Already cancelled: back to the page as it is, without a message, and no email.
        if ($cancelled) {
            // After the transaction, which is committed: the queued email never runs for a
            // cancellation that was rolled back. A failure while queueing it is reported, and the
            // booking stays cancelled.
            CustomerCancelledNotification::sendFor($booking);

            Inertia::flash('toast', ['type' => 'success', 'message' => __('booking.cancel.cancelled')]);
        }

        // Back to the customer's private page: the token goes in the address as a string, not as
        // the model, and never in the flash message.
        return to_route('bookings.show', ['booking' => $booking->public_token]);
    }
}
