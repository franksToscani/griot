<?php

namespace App\Http\Middleware;

use App\Support\LinkPreview;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Each request starts with a private page (App\Support\LinkPreview): a fresh one here, so nothing
 * of a previous request (tests, long-running workers) is left. After the controller, a page that
 * is still not public also says "noindex, nofollow" in a header, for the crawlers that read
 * headers rather than the HTML. A header already set by a more specific middleware
 * (PrivateBookingPage, PrivateProviderPage) stays.
 */
class NoIndexPrivatePages
{
    public function handle(Request $request, Closure $next): Response
    {
        $preview = new LinkPreview;
        app()->instance(LinkPreview::class, $preview);

        $response = $next($request);

        if (! $preview->isPublic() && ! $response->headers->has('X-Robots-Tag')) {
            $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        }

        return $response;
    }
}
