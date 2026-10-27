<?php

namespace App\Http\Controllers;

use App\Booking\BookingOutcome;
use App\Booking\ManageBooking;
use App\Booking\TransitionNotAllowed;
use App\Http\Requests\RejectBookingRequest;
use App\Notifications\BookingStatusNotification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * Rejects a pending booking, even a past one, with an optional message. The slot is free again at once.
 */
class ProviderBookingRejectController extends Controller
{
    /**
     * Handle the incoming request. The booking was found among the user's own by the request (404
     * otherwise); only "reason" is read from the body.
     */
    public function __invoke(RejectBookingRequest $request, ManageBooking $manageBooking): RedirectResponse
    {
        $booking = $request->ownBooking();

        if ($booking === null) {
            return to_route('provider.setup');
        }

        // The policy compares provider_id with the user's provider, already loaded: no query.
        Gate::authorize('manage', $booking);

        try {
            $booking = $manageBooking->reject($booking, $request->reason());
        } catch (TransitionNotAllowed $exception) {
            // redirectTo(): the Referer is not reliable.
            throw ValidationException::withMessages(['booking' => __('provider.bookings.transition.'.$exception->reason())])
                ->redirectTo(route('provider.bookings.index'));
        }

        // After the transaction, which is committed: the queued email never runs for a change that was
        // rolled back. A failure while queueing it is reported, and the action stays done.
        BookingStatusNotification::sendFor($booking, BookingOutcome::Rejected);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('provider.bookings.rejected')]);

        return to_route('provider.bookings.index');
    }
}
