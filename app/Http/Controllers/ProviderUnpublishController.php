<?php

namespace App\Http\Controllers;

use App\Profile\PublishProvider;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

/**
 * Takes the logged-in provider off the public site, from the dashboard. Always allowed.
 */
class ProviderUnpublishController extends Controller
{
    /**
     * Handle the incoming request. No fields: whatever is sent in the body has no effect. The
     * provider is always $request->user()->provider.
     */
    public function __invoke(Request $request, PublishProvider $publishProvider): RedirectResponse
    {
        $provider = $request->user()?->provider;

        if ($provider === null) {
            return to_route('provider.setup');
        }

        // Always true today, since the provider is the user's own: defence in depth, so that the
        // check is already there if one day the route receives an identifier.
        Gate::authorize('update', $provider);

        $publishProvider->unpublish($provider);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('provider.publish.unpublished')]);

        return to_route('dashboard');
    }
}
