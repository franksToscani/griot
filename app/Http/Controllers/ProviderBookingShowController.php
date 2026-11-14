<?php

namespace App\Http\Controllers;

use App\Http\Resources\ProviderBookingResource;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * One booking of the logged-in provider, with the same answers as the list: the page the email
 * "nuova prenotazione" links to. The booking is searched only among the user's own bookings: an id
 * of another provider and an id that does not exist get the same 404, never a 403, so the page
 * does not tell whether that booking exists.
 */
class ProviderBookingShowController extends Controller
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
        // The policy compares provider_id with the user's provider, already loaded: no query.
        Gate::authorize('manage', $booking);

        // The provider already loaded, with no query (the WhatsApp message names the provider).
        $booking->setRelation('provider', $provider);

        return Inertia::render('provider/bookings/show', [
            'booking' => ProviderBookingResource::make($booking)->resolve($request),
        ]);
    }
}
