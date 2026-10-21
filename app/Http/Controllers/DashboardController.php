<?php

namespace App\Http\Controllers;

use App\Http\Resources\ProviderAreaResource;
use App\Profile\PublishingRequirements;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The private area of the logged-in provider.
 */
class DashboardController extends Controller
{
    /**
     * Handle the incoming request. The provider is always the logged-in user's own, never read
     * from the address or the request. A user without a provider goes to provider.setup, which
     * in turn sends a user with a provider here: the two routes exclude each other, no loop.
     */
    public function __invoke(Request $request, PublishingRequirements $requirements): Response|RedirectResponse
    {
        $provider = $request->user()?->provider;

        if ($provider === null) {
            return to_route('provider.setup');
        }

        // Computed at every load, so it always reflects the current services and availabilities.
        $missing = $requirements->missing($provider);

        // Unpublished too: the page shows the status.
        return Inertia::render('dashboard', [
            // resolve() gives the provider's fields directly, without a "data" wrapper.
            'provider' => (new ProviderAreaResource($provider))->resolve($request),
            // What the provider still needs before publishing: codes of PublishingRequirements.
            'publishing' => [
                'can_publish' => $missing === [],
                'missing' => $missing,
            ],
        ]);
    }
}
