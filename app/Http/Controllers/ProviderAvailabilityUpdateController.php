<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateAvailabilityRequest;
use App\Profile\AvailabilityLimitReached;
use App\Profile\AvailabilityOverlaps;
use App\Profile\ManageAvailability;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * Saves the changes to a weekly time slot. The slots offered change at once; the bookings already
 * made do not change: their start and end are saved and never computed again.
 */
class ProviderAvailabilityUpdateController extends Controller
{
    /**
     * Handle the incoming request. The slot was found among the user's own by the request (404
     * otherwise); provider_id and id in the body have no effect.
     */
    public function __invoke(UpdateAvailabilityRequest $request, ManageAvailability $manageAvailability): RedirectResponse
    {
        $availability = $request->ownAvailability();

        if ($availability === null) {
            return to_route('provider.setup');
        }

        // The policy compares provider_id with the user's provider, already loaded: no query.
        Gate::authorize('update', $availability);

        $page = route('provider.availability.index');

        try {
            $manageAvailability->update($availability, $request->availabilityData());
        } catch (AvailabilityLimitReached) {
            // redirectTo(): the Referer is not reliable.
            throw ValidationException::withMessages([
                'weekday' => __('provider.availability.validation.limit_reached', ['max' => ManageAvailability::MAX_PER_DAY]),
            ])->redirectTo($page);
        } catch (AvailabilityOverlaps) {
            throw ValidationException::withMessages(['start_time' => __('provider.availability.validation.overlap')])->redirectTo($page);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('provider.availability.updated')]);

        return redirect()->to($page);
    }
}
