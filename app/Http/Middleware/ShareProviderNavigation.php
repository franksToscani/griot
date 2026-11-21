<?php

namespace App\Http\Middleware;

use App\Profile\SetupSteps;
use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * The data of the provider's sidebar (components/app-sidebar.tsx), shared as "provider_nav" with the
 * pages of the private area and of the settings only: never with the public pages, not even for a
 * logged-in provider (docs/bookings.md, "Barra dell'area del professionista").
 *
 * - pending_requests: the requests that wait for an answer (Booking::awaitingAnswer()), one COUNT;
 * - public_slug: the slug while the profile is published, for "Pagina pubblica"; null otherwise.
 *
 * And "setup_steps", the guide to the first steps while the provider is not published (SetupSteps).
 *
 * null for a user without a provider. A closure: Inertia resolves it only when the page is drawn,
 * and in a partial reload only when "provider_nav" is in "only" (lib/live-pages.ts). The provider is
 * the one the controller has usually read already, on the same user: no query more.
 */
class ShareProviderNavigation
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Inertia keeps the shared props for the whole process: put back what was there before,
        // so that the next request (a public page, in a test or in a worker) never gets it.
        $before = Inertia::getShared();

        // The guide to the first steps (App\Profile\SetupSteps): only on the four pages of the steps,
        // only while the provider is not published, from the provider of the session; null
        // otherwise. Its own prop, so the partial reloads that ask "provider_nav"
        // (lib/live-pages.ts) never compute it, and the other pages of the area ask nothing more.
        Inertia::share('setup_steps', function () use ($request): ?array {
            $provider = $request->user()?->provider;

            if ($provider === null || ! $request->routeIs(...SetupSteps::PAGES)) {
                return null;
            }

            return app(SetupSteps::class)->for($request, $provider);
        });

        Inertia::share('provider_nav', function () use ($request): ?array {
            $provider = $request->user()?->provider;

            if ($provider === null) {
                return null;
            }

            return [
                'pending_requests' => $provider->bookings()->awaitingAnswer()->count(),
                'public_slug' => $provider->is_published ? $provider->slug : null,
            ];
        });

        try {
            return $next($request);
        } finally {
            Inertia::flushShared();
            Inertia::share($before);
        }
    }
}
