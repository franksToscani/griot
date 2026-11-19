<?php

namespace App\Http\Controllers;

use App\Booking\CalendarEvent;
use App\Booking\IcsFile;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * The .ics file of one of the logged-in provider's bookings (docs/bookings.md), next to "Add to
 * Google Calendar". As ProviderBookingShowController: the booking is searched only among the user's
 * own, so an id of another provider and an id that does not exist get the same 404, and the policy
 * checks again. A booking without an event (not confirmed, anonymous, already started: the rule of
 * CalendarEvent) is a 404 too. The headers of the provider's private pages come from the route
 * (PrivateProviderPage): never stored in a cache, never indexed.
 */
class ProviderBookingCalendarController extends Controller
{
    public function __invoke(Request $request): Response|RedirectResponse
    {
        $provider = $request->user()?->provider;

        if ($provider === null) {
            return to_route('provider.setup');
        }

        $booking = $provider->bookings()
            ->with(['service', 'options'])
            ->whereKey($request->route('booking'))
            ->firstOrFail();
        Gate::authorize('manage', $booking);

        $event = CalendarEvent::for($booking);
        abort_if($event === null, 404);

        // A file name without any personal datum: the id only.
        return response(IcsFile::for($event), 200, [
            'Content-Type' => 'text/calendar; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="prenotazione-'.$booking->id.'.ics"',
        ]);
    }
}
