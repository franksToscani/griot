<?php

namespace App\Http\Controllers;

use App\Profile\ManageOption;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

/**
 * Offers a hidden option to new customers again.
 */
class ProviderOptionActivateController extends Controller
{
    /**
     * Handle the incoming request. The service is searched only among the user's own, the option
     * only among the options of that service: any other id gets the same 404. No fields: whatever
     * is sent in the body is ignored.
     */
    public function __invoke(Request $request, ManageOption $manageOption): RedirectResponse
    {
        $provider = $request->user()?->provider;

        if ($provider === null) {
            return to_route('provider.setup');
        }

        $service = $provider->services()->whereKey($request->route('service'))->firstOrFail();
        $option = $service->options()->whereKey($request->route('option'))->firstOrFail();
        // The option was found through its service: no query for the policy.
        $option->setRelation('service', $service);
        Gate::authorize('update', $option);

        $manageOption->activate($option);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('provider.options.activated')]);

        return to_route('provider.services.options.index', $service->id);
    }
}
