<?php

namespace App\Http\Controllers;

use Illuminate\Http\Response;

/**
 * /robots.txt from the config (docs/production.md). Off, the same text public/robots.txt had:
 * every crawler may read everything. While the site is private (SITE_NOINDEX=true): nothing.
 */
class RobotsController extends Controller
{
    public const OPEN = "User-agent: *\nDisallow:\n";

    public const PRIVATE = "User-agent: *\nDisallow: /\n";

    public function __invoke(): Response
    {
        return response(config()->boolean('app.noindex') ? self::PRIVATE : self::OPEN, 200, [
            'Content-Type' => 'text/plain; charset=UTF-8',
        ]);
    }
}
