<?php

namespace App\Http\Controllers;

use App\Support\BrandColours;
use Illuminate\Http\JsonResponse;

/**
 * /manifest.webmanifest: what a phone or a computer needs to install Griot as an app on its home
 * screen (docs/production.md). Only installation: no service worker, so nothing works offline
 * and no page is kept by the app; the private pages keep their no-store.
 *
 * The browser downloads the manifest without cookies: it cannot know who installs the app, a
 * customer or a provider, nor their language. So one manifest for everyone, the app opens on the
 * home page, the name is the brand and there is no description to translate. Outside the "web"
 * group (bootstrap/app.php), like robots.txt: no session, no cookie.
 */
class WebAppManifestController extends Controller
{
    public const NAME = 'Griot';

    public const CONTENT_TYPE = 'application/manifest+json';

    /**
     * The icons: "any" with round corners, "maskable" square and opaque (scripts/brand-assets.php).
     *
     * @var list<array{src: string, sizes: string, purpose: string}>
     */
    public const ICONS = [
        ['src' => '/brand/icon-192.png', 'sizes' => '192x192', 'purpose' => 'any'],
        ['src' => '/brand/icon-512.png', 'sizes' => '512x512', 'purpose' => 'any'],
        ['src' => '/brand/icon-maskable-192.png', 'sizes' => '192x192', 'purpose' => 'maskable'],
        ['src' => '/brand/icon-maskable-512.png', 'sizes' => '512x512', 'purpose' => 'maskable'],
    ];

    public function __invoke(): JsonResponse
    {
        return response()->json(self::manifest(), 200, [
            'Content-Type' => self::CONTENT_TYPE,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * @return array<string, mixed>
     */
    public static function manifest(): array
    {
        return [
            // The identity of the app: it stays the same even if start_url changes one day.
            'id' => '/',
            'name' => self::NAME,
            'short_name' => self::NAME,
            'start_url' => '/',
            'scope' => '/',
            'display' => 'standalone',
            'theme_color' => BrandColours::hex(BrandColours::BROWN),
            'background_color' => BrandColours::hex(BrandColours::BACKGROUND),
            'icons' => array_map(fn (array $icon) => [...$icon, 'type' => 'image/png'], self::ICONS),
        ];
    }
}
