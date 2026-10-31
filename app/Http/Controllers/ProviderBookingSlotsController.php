<?php

namespace App\Http\Controllers;

use App\Booking\FreeSlots;
use App\Booking\SlotRules;
use App\Http\Controllers\Concerns\RespondsWithFreeSlots;
use App\Http\Requests\ProviderSlotsRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

/**
 * The free slots of the logged-in provider, to write a booking in the own agenda: the same answer
 * as the public route, with the provider's rules (no minimum notice). Not to be confused with
 * ProviderSlotsController, the public one.
 */
class ProviderBookingSlotsController extends Controller
{
    use RespondsWithFreeSlots;

    /**
     * Handle the incoming request. The provider is always $request->user()->provider, never an
     * identifier from the address or the query.
     */
    public function __invoke(ProviderSlotsRequest $request, FreeSlots $freeSlots): JsonResponse|RedirectResponse
    {
        $provider = $request->user()?->provider;

        if ($provider === null) {
            return to_route('provider.setup');
        }

        // Always true today, since the provider is the user's own: defence in depth, as on the
        // bookings page.
        Gate::authorize('update', $provider);

        return $this->freeSlotsResponse($request, $provider, $freeSlots, SlotRules::forProvider());
    }
}
