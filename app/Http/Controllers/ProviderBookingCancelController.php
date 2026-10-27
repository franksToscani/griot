<?php

namespace App\Http\Controllers;

use App\Booking\ManageBooking;
use App\Booking\TransitionNotAllowed;
use App\Http\Requests\RejectBookingRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * Cancels a confirmed booking, even a past one, with an optional message. The slot is free again at once.
 */
class ProviderBookingCancelController extends Controller
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
            $manageBooking->cancel($booking, $request->reason());
        } catch (TransitionNotAllowed $exception) {
            // redirectTo(): the Referer is not reliable.
            throw ValidationException::withMessages(['booking' => __('provider.bookings.transition.'.$exception->reason())])
                ->redirectTo(route('provider.bookings.index'));
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('provider.bookings.cancelled_by_provider')]);

        return to_route('provider.bookings.index');
    }
}
