<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Headers for a page that only its provider can see, such as the preview of the public page, the
 * list of the bookings and the page of one booking (the customers' names, phones and notes): it
 * must not be stored in any cache, the browser's included, or indexed. They apply when the page is the loaded document;
 * Inertia visits rely on the meta tag of the page as well.
 */
class PrivateProviderPage
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $response;
    }
}
