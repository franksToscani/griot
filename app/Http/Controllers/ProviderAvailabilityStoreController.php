<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreAvailabilityRequest;
use App\Profile\AvailabilityLimitReached;
use App\Profile\AvailabilityOverlaps;
use App\Profile\ManageAvailability;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * Adds a weekly time slot to the logged-in provider.
 */
class ProviderAvailabilityStoreController extends Controller
{
    /**
     * Handle the incoming request. The provider is always $request->user()->provider, and the slot
     * is created through its relation: provider_id never comes from the input.
     */
    public function __invoke(StoreAvailabilityRequest $request, ManageAvailability $manageAvailability): RedirectResponse
    {
        $provider = $request->user()?->provider;

        if ($provider === null) {
            return to_route('provider.setup');
        }

        Gate::authorize('update', $provider);

        $page = route('provider.availability.index');

        try {
            $manageAvailability->create($provider, $request->availabilityData());
        } catch (AvailabilityLimitReached) {
            // redirectTo(): the Referer is not reliable.
            throw ValidationException::withMessages([
                'weekday' => __('provider.availability.validation.limit_reached', ['max' => ManageAvailability::MAX_PER_DAY]),
            ])->redirectTo($page);
        } catch (AvailabilityOverlaps) {
            throw ValidationException::withMessages(['start_time' => __('provider.availability.validation.overlap')])->redirectTo($page);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('provider.availability.created')]);

        return redirect()->to($page);
    }
}
