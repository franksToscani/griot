<?php

namespace App\Http\Controllers;

use App\Profile\ManageService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

/**
 * Hides one of the logged-in provider's services from the public page and the bookable slots.
 */
class ProviderServiceDeactivateController extends Controller
{
    /**
     * Handle the incoming request. The service is searched only among the user's own (404 for any
     * other id). No fields: whatever is sent in the body is ignored.
     */
    public function __invoke(Request $request, ManageService $manageService): RedirectResponse
    {
        $provider = $request->user()?->provider;

        if ($provider === null) {
            return to_route('provider.setup');
        }

        $service = $provider->services()->whereKey($request->route('service'))->firstOrFail();
        Gate::authorize('update', $service);

        $manageService->deactivate($service);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('provider.services.deactivated')]);

        return to_route('provider.services.index');
    }
}
