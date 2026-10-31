<?php

namespace App\Http\Controllers;

use App\Booking\FreeSlots;
use App\Http\Resources\ServiceResource;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The page where the logged-in provider writes a booking in the own agenda. The form is sent to
 * ProviderBookingStoreController; the times come from ProviderBookingSlotsController.
 */
class ProviderBookingCreateController extends Controller
{
    /**
     * Handle the incoming request. The services are the same the public page offers: the active
     * ones, by name, each with the options still offered to new customers.
     */
    public function __invoke(Request $request, FreeSlots $freeSlots): Response|RedirectResponse
    {
        $provider = $request->user()?->provider;

        if ($provider === null) {
            return to_route('provider.setup');
        }

        // Always true today, since the provider is the user's own: defence in depth, as on the
        // bookings page.
        Gate::authorize('update', $provider);

        $services = $provider->services()
            ->active()
            ->orderBy('name')
            // One query for all the options, however many services.
            ->with(['options' => fn ($options) => $options->active()->orderBy('id')])
            ->get();

        return Inertia::render('provider/bookings/create', [
            // resolve() gives plain lists, without a "data" wrapper, as on the public page.
            'services' => ServiceResource::collection($services)->resolve($request),
            // The days the form offers: the same rule the server applies.
            'bookingWindow' => $freeSlots->bookingWindow(),
            // An unpublished provider has no bookable times: the page explains it instead of the form.
            'published' => $provider->is_published,
        ]);
    }
}
