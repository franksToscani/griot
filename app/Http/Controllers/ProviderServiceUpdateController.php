<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateServiceRequest;
use App\Profile\ManageService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

/**
 * Saves the changes to one of the logged-in provider's services. The bookings already made do not
 * change: they keep their own times.
 */
class ProviderServiceUpdateController extends Controller
{
    /**
     * Handle the incoming request. The service is searched only among the user's own (404 for any
     * other id); provider_id, id and is_active in the body have no effect.
     */
    public function __invoke(UpdateServiceRequest $request, ManageService $manageService): RedirectResponse
    {
        $provider = $request->user()?->provider;

        if ($provider === null) {
            return to_route('provider.setup');
        }

        $service = $provider->services()->whereKey($request->route('service'))->firstOrFail();
        Gate::authorize('update', $service);

        $manageService->update($service, $request->serviceData());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('provider.services.updated')]);

        return to_route('provider.services.index');
    }
}
