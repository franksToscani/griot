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
     * cookie that is not listed in bootstrap/app.php. A logged-in user keeps the choice too
     * (users.locale), for a new device and for the emails. Back to the page where the choice was made.
     */
    public function __invoke(UpdateLocaleRequest $request): RedirectResponse
    {
        $user = $request->user();

        if ($user !== null) {
            // Not fillable: assigned here only, with a value already validated.
            $user->locale = $request->locale();
            $user->save();
        }

        return back(fallback: route('home'))
            ->withCookie(cookie(SetLocale::COOKIE, $request->locale(), self::COOKIE_MINUTES));
    }
}
