<?php

namespace App\Http\Controllers;

use App\Booking\ManageBooking;
use App\Booking\TransitionNotAllowed;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * The provider confirms a pending booking that has not started yet.
 */
class ProviderBookingConfirmController extends Controller
{
    /**
     * Handle the incoming request. The booking is searched only among the user's own: any other id
     * gets the same 404. The request has no fields.
     */
    public function __invoke(Request $request, ManageBooking $manageBooking): RedirectResponse
    {
        $provider = $request->user()?->provider;

        if ($provider === null) {
            return to_route('provider.setup');
        }

        $booking = $provider->bookings()->whereKey($request->route('booking'))->firstOrFail();
        // The policy compares provider_id with the user's provider, already loaded: no query.
        Gate::authorize('manage', $booking);

        try {
            $manageBooking->confirm($booking);
        } catch (TransitionNotAllowed $exception) {
            // redirectTo(): the Referer is not reliable.
            throw ValidationException::withMessages(['booking' => __('provider.bookings.transition.'.$exception->reason())])
                ->redirectTo(route('provider.bookings.index'));
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('provider.bookings.confirmed')]);

        return to_route('provider.bookings.index');
    }
}
