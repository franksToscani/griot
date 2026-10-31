<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The language of the request: the one chosen with the language selector (the "locale" cookie,
 * written by LocaleController), if it is still among the available ones. Otherwise the default
 * one, app.locale: the browser's Accept-Language is never read, the site starts in Italian.
 *
 * It runs before HandleInertiaRequests, so the shared translations, <html lang> and the dates of
 * Carbon all follow it.
 */
class SetLocale
{
    /**
     * The cookie that remembers the visitor's choice.
     */
    public const COOKIE = 'locale';

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $chosen = $request->cookie(self::COOKIE);

        if (is_string($chosen) && array_key_exists($chosen, config()->array('app.available_locales'))) {
            app()->setLocale($chosen);
        }

        return $next($request);
    }
}
