<?php

namespace App\Http;

use App\Http\Middleware\SetLocale;
use App\Support\Locales;
use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Throwable;

/**
 * The language a request asks for: the one in the "locale" cookie, written by LocaleController,
 * if it is still among the available ones; null otherwise, and the default language stays.
 *
 * Used by SetLocale and, for the error pages, by the exception handler (bootstrap/app.php): a
 * 419 or a 404 without a route is rendered before SetLocale runs, sometimes before the cookies
 * are even decrypted. It never throws: a cookie it cannot read is no choice.
 */
final class LocaleFromRequest
{
    /**
     * @param  bool  $encrypted  whether the cookie still holds its encrypted value: true when
     *                           EncryptCookies has not run on the request (an error before the route)
     */
    public static function resolve(Request $request, bool $encrypted = false): ?string
    {
        try {
            $value = $request->cookies->get(SetLocale::COOKIE);

            if (! is_string($value) || $value === '') {
                return null;
            }

            if ($encrypted) {
                // As EncryptCookies does: decrypt, then check and remove the prefix that binds the
                // value to this cookie's name. A wrong or plain value gives null.
                $value = CookieValuePrefix::validate(SetLocale::COOKIE, Crypt::decryptString($value), Crypt::getAllKeys());
            }

            return is_string($value) ? Locales::available($value) : null;
        } catch (Throwable) {
            return null;
        }
    }
}
