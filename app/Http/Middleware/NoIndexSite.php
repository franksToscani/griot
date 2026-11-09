<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * While the site is private (SITE_NOINDEX=true, config app.noindex, docs/production.md), every
 * response says "X-Robots-Tag: noindex, nofollow": the pages, /up, the errors and the files PHP
 * serves. It replaces the header of a public page. The static files served by the web server
 * itself get the same header from docker/Caddyfile. Off, it changes nothing.
 */
class NoIndexSite
{
    public const VALUE = 'noindex, nofollow';

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (config()->boolean('app.noindex')) {
            $response->headers->set('X-Robots-Tag', self::VALUE);
        }

        return $response;
    }
}
