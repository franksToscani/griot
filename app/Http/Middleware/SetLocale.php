<?php

namespace App\Http\Middleware;

use App\Http\LocaleFromRequest;
use App\Support\Locales;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The language of the request, in this order: the one chosen with the language selector in this
 * browser (the "locale" cookie, written by LocaleController); then the one the logged-in user chose
 * (users.locale), useful on a new device; then the default one, app.locale. Each only if it is
 * still among the available ones. The browser's Accept-Language is never read.
 * LocaleFromRequest reads and checks the cookie. The private page of a booking can still change it
 * (UseBookingLocale).
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
        // EncryptCookies has already run: the cookie holds its plain value. The user is the one of
        // the session, read once for the whole request.
        $chosen = LocaleFromRequest::resolve($request) ?? Locales::available($request->user()?->locale);

        if ($chosen !== null) {
            app()->setLocale($chosen);
        }

        return $next($request);
    }
}
