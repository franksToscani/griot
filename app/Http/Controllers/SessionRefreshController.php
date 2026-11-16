<?php

namespace App\Http\Controllers;

use Illuminate\Http\Response;

/**
 * A new CSRF token after a 419 (issue #130, resources/js/lib/http-errors.ts). An empty answer:
 * the token travels in the XSRF-TOKEN cookie that PreventRequestForgery adds to every request of
 * the web group that passes it, a GET always. No authentication: the public booking form and the
 * customer's private page need it too. Never stored by a cache.
 */
class SessionRefreshController extends Controller
{
    public function __invoke(): Response
    {
        return response()->noContent()->header('Cache-Control', 'no-store, private');
    }
}
