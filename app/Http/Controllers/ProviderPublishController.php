<?php

namespace App\Http\Controllers;

use App\Profile\CannotPublish;
use App\Profile\PublishProvider;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * Publishes the logged-in provider, from the dashboard.
 */
class ProviderPublishController extends Controller
{
    /**
     * Handle the incoming request. No fields: is_published, slug or provider_id in the body have no
     * effect. The provider is always $request->user()->provider.
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

        try {
            $publishProvider->publish($provider);
        } catch (CannotPublish) {
            // Only the message: the dashboard shows what is missing from its "publishing" prop,
            // computed again when it reloads. redirectTo(): the Referer is not reliable.
            throw ValidationException::withMessages(['publish' => __('provider.publish.blocked')])
                ->redirectTo(route('dashboard'));
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('provider.publish.published')]);

        return to_route('dashboard');
    }
}
