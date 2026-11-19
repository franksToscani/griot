<?php

namespace App\Support;

/**
 * An absolute address of the site, always on APP_URL (config('app.url')), never on the Host header
 * of the request: the site may be reached by another name (the onrender.com address of the
 * service, a proxy), and the links that leave the site (previews, "Il tuo link") must carry the
 * public one. When the domain changes, APP_URL changes and every address follows (docs/production.md).
 */
final class PublicUrl
{
    /**
     * "providers/salone-awa", "/providers/salone-awa" or "" give "https://griot.example.test/providers/salone-awa"
     * and "https://griot.example.test".
     */
    public static function to(string $path): string
    {
        $path = trim($path, '/');

        return rtrim((string) config('app.url'), '/').($path === '' ? '' : '/'.$path);
    }
}
