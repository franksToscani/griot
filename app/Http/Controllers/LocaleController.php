<?php

namespace App\Http\Controllers;

use App\Http\Middleware\SetLocale;
use App\Http\Requests\UpdateLocaleRequest;
use Illuminate\Http\RedirectResponse;

/**
 * Remembers the language chosen with the language selector, for this browser. SetLocale reads it
 * at every request.
 */
class LocaleController extends Controller
{
    /**
     * How long the browser keeps the choice: one year, in minutes.
     */
    private const COOKIE_MINUTES = 60 * 24 * 365;

    /**
     * Handle the incoming request. The cookie is written by the server and encrypted, like every
     * cookie that is not listed in bootstrap/app.php. Back to the page where the choice was made.
     */
    public function __invoke(UpdateLocaleRequest $request): RedirectResponse
    {
        return back(fallback: route('home'))
            ->withCookie(cookie(SetLocale::COOKIE, $request->locale(), self::COOKIE_MINUTES));
    }
}
