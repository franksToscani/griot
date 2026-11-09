<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The security headers of every response (docs/production.md):
 *
 * - X-Content-Type-Options: nosniff, the browser never guesses another type than the one sent;
 * - Referrer-Policy: strict-origin-when-cross-origin, another site sees our domain only, never the
 *   path (the private page of a booking has its code in it);
 * - X-Frame-Options: SAMEORIGIN, no other site shows our pages in a frame;
 * - Strict-Transport-Security, in production only and only on an https request: the browser then
 *   refuses plain http for a year. Not on subdomains, not preloaded: the domain is not chosen yet.
 *
 * No Content-Security-Policy yet: the inline script of the theme in app.blade.php needs a nonce
 * first. A header already set by a controller is never replaced.
 */
class SecurityHeaders
{
    /**
     * HSTS: one year.
     */
    public const HSTS = 'max-age=31536000';

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $headers = $response->headers;

        foreach ([
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'X-Frame-Options' => 'SAMEORIGIN',
        ] as $name => $value) {
            if (! $headers->has($name)) {
                $headers->set($name, $value);
            }
        }

        if (app()->isProduction() && $request->isSecure() && ! $headers->has('Strict-Transport-Security')) {
            $headers->set('Strict-Transport-Security', self::HSTS);
        }

        return $response;
    }
}
