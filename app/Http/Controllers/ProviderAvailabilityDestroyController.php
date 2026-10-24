<?php

namespace App\Http\Controllers;

use App\Profile\ManageAvailability;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

/**
 * Deletes a weekly time slot, even the last one. The bookings already made do not change.
 */
class ProviderAvailabilityDestroyController extends Controller
{
    /**
     * Handle the incoming request. The slot is searched only among the user's own: any other id
     * gets the same 404.
     */
    public function __invoke(Request $request, ManageAvailability $manageAvailability): RedirectResponse
    {
        $provider = $request->user()?->provider;

        if ($provider === null) {
            return to_route('provider.setup');
        }

        $availability = $provider->availabilities()->whereKey($request->route('availability'))->firstOrFail();
        Gate::authorize('delete', $availability);

        $manageAvailability->delete($availability);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('provider.availability.deleted')]);

        return to_route('provider.availability.index');
    }
}
