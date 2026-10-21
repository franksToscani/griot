<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreServiceRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

/**
 * Adds a service to the logged-in provider.
 */
class ProviderServiceStoreController extends Controller
{
    /**
     * Handle the incoming request. The provider is always $request->user()->provider, and the
     * service is created through its relation: provider_id never comes from the input.
     */
    public function __invoke(StoreServiceRequest $request): RedirectResponse
    {
        $provider = $request->user()?->provider;

        if ($provider === null) {
            return to_route('provider.setup');
        }

        // Always true today, since the provider is the user's own: defence in depth, so that the
        // check is already there if one day the route receives an identifier.
        Gate::authorize('update', $provider);

        $provider->services()->create($request->serviceData());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('provider.services.created')]);

        return to_route('provider.services.index');
    }
}
