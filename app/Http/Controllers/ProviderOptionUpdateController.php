<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateOptionRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

/**
 * Saves the changes to an option. The bookings already made do not change: their times are saved
 * and never computed again.
 */
class ProviderOptionUpdateController extends Controller
{
    /**
     * Handle the incoming request. Service and option were found among the user's own by the
     * request (404 otherwise); service_id, id and is_active in the body have no effect.
     */
    public function __invoke(UpdateOptionRequest $request): RedirectResponse
    {
        $service = $request->ownService();
        $option = $request->ownOption();

        if ($service === null || $option === null) {
            return to_route('provider.setup');
        }

        // The option was found through its service: no query for the policy.
        $option->setRelation('service', $service);
        Gate::authorize('update', $option);

        $option->fill($request->optionData());
        $option->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('provider.options.updated')]);

        return to_route('provider.services.options.index', $service->id);
    }
}
