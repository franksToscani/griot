<?php

namespace App\Http\Controllers;

use App\Booking\FreeSlots;
use App\Http\Resources\ProviderProfileResource;
use App\Models\Provider;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The logged-in provider's public page as the customers see it, published or not. The public
 * address keeps giving a 404 for an unpublished provider: this page has no slug, it is always the
 * user's own provider.
 */
class ProviderPreviewController extends Controller
{
    /**
     * Handle the incoming request. The same data as the public page, from the same scope and the
     * same resource; only the "published" filter is left out.
     */
    public function __invoke(Request $request, FreeSlots $freeSlots): Response|RedirectResponse
    {
        $provider = $request->user()?->provider;

        if ($provider === null) {
            return to_route('provider.setup');
        }

        // Always true today, since the provider is the user's own: defence in depth, as on the
        // services page.
        Gate::authorize('update', $provider);

        $profile = Provider::query()->withPublicProfile()->whereKey($provider->id)->firstOrFail();

        return Inertia::render('providers/show', [
            // resolve() gives the provider's fields directly, without a "data" wrapper.
            'provider' => (new ProviderProfileResource($profile))->resolve($request),
            'bookingWindow' => $freeSlots->bookingWindow(),
            // The page shows the preview notice and offers no booking.
            'preview' => true,
        ]);
    }
}
