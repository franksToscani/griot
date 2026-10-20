<?php

namespace App\Http\Controllers;

use App\Http\Resources\ProviderEditResource;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The page where the logged-in provider edits its profile.
 */
class ProviderEditController extends Controller
{
    /**
     * Handle the incoming request. The provider is always the logged-in user's own.
     */
    public function __invoke(Request $request): Response|RedirectResponse
    {
        $provider = $request->user()?->provider;

        if ($provider === null) {
            return to_route('provider.setup');
        }

        // Always true today, since the provider is the user's own: defence in depth, so that the
        // check is already there if one day the route receives an identifier.
        Gate::authorize('update', $provider);

        return Inertia::render('provider/edit', [
            // resolve() gives the provider's fields directly, without a "data" wrapper.
            'provider' => (new ProviderEditResource($provider))->resolve($request),
        ]);
    }
}
