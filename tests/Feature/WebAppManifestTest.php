<?php

use App\Http\Controllers\WebAppManifestController;
use App\Models\Booking;
use App\Support\BrandColours;

// Griot installable on a home screen (docs/production.md): the manifest at /manifest.webmanifest,
// its icons, the tags of the pages, and no service worker, so the private pages keep their
// headers and nothing is kept offline.

/**
 * The manifest as the browser receives it.
 *
 * @return array<string, mixed>
 */
function wamManifest(): array
{
    $response = test()->get('/manifest.webmanifest')->assertOk();

    return json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR);
}

/**
 * The sRGB hex of a token of app.css (the formulas of tests/Pest.php, as BrandAssetsTest).
 */
function wamToken(string $name, string $theme = 'light'): string
{
    return sprintf('#%02X%02X%02X', ...array_map(fn (float $channel) => (int) round($channel * 255), accSrgb(accTokens($theme)[$name])));
}

/**
 * The colour and the alpha (0 opaque, 127 transparent) of a pixel.
 *
 * @return array{0: int, 1: int, 2: int, 3: int}
 */
function wamPixel(GdImage $image, int $x, int $y): array
{
    $pixel = imagecolorat($image, $x, $y);

    return [($pixel >> 16) & 0xFF, ($pixel >> 8) & 0xFF, $pixel & 0xFF, ($pixel >> 24) & 0x7F];
}

/**
 * The image of an icon of the manifest, read from public/.
 */
function wamImage(string $src): GdImage
{
    $image = imagecreatefrompng(public_path(ltrim($src, '/')));
    expect($image)->not->toBeFalse($src);

    return $image;
}

// ---------------------------------------------------------------------------------------------
// The response
// ---------------------------------------------------------------------------------------------

test('the manifest is a JSON of its own type, without cookie and without session', function () {
    $response = $this->get('/manifest.webmanifest')
        ->assertOk()
        ->assertHeader('Content-Type', 'application/manifest+json')
        ->assertHeaderMissing('Set-Cookie');

    expect($response->headers->getCookies())->toBe([])
        ->and(json_decode((string) $response->getContent(), true))->toBeArray()
        ->and(route('manifest'))->toBe(url('/manifest.webmanifest'))
        // Outside the "web" group, like robots.txt: the browser sends no cookie for it anyway.
        ->and(app('router')->getRoutes()->getByName('manifest')?->gatherMiddleware())->not->toContain('web');
    // No file in public/ that would win over the route.
    expect(file_exists(public_path('manifest.webmanifest')))->toBeFalse()
        ->and(file_exists(public_path('manifest.json')))->toBeFalse();
});

test('the manifest holds what Chrome, Edge and Safari ask, with the values decided', function () {
    $manifest = wamManifest();

    expect($manifest)->toBe(WebAppManifestController::manifest())
        ->and(array_keys($manifest))->toBe(['id', 'name', 'short_name', 'start_url', 'scope', 'display', 'theme_color', 'background_color', 'icons'])
        ->and($manifest['id'])->toBe('/')
        ->and($manifest['name'])->toBe('Griot')
        ->and($manifest['short_name'])->toBe('Griot')
        // The home page for everyone: the manifest cannot know who installs it.
        ->and($manifest['start_url'])->toBe('/')
        ->and($manifest['scope'])->toBe('/')
        ->and($manifest['display'])->toBe('standalone');

    // Not locked to one orientation (WCAG 1.3.4), no store app preferred, nothing to translate.
    foreach (['orientation', 'prefer_related_applications', 'related_applications', 'description', 'shortcuts', 'serviceworker'] as $member) {
        expect($manifest)->not->toHaveKey($member);
    }
});

test('the colours are those of the logo, from the same constants as the icons and the tokens of app.css', function () {
    $manifest = wamManifest();

    expect($manifest['theme_color'])->toBe(BrandColours::hex(BrandColours::BROWN))
        ->and($manifest['background_color'])->toBe(BrandColours::hex(BrandColours::BACKGROUND))
        // The tokens themselves, read from app.css: --hero and --background.
        ->and($manifest['theme_color'])->toBe(wamToken('hero'))
        ->and($manifest['background_color'])->toBe(wamToken('background'));
});

test('the manifest never points to a private page: neither the booking of a customer nor the provider\'s area', function () {
    $booking = Booking::factory()->create();
    $text = (string) $this->get('/manifest.webmanifest')->getContent();

    expect($text)
        ->not->toContain('/bookings')
        ->not->toContain('/provider')
        ->not->toContain('/dashboard')
        ->not->toContain('/settings')
        ->not->toContain($booking->public_token);
    // Every address of the manifest is the home page or an icon.
    preg_match_all('#"(/[^"]*)"#', str_replace('\/', '/', $text), $paths);
    foreach ($paths[1] as $path) {
        expect($path === '/' || str_starts_with($path, '/brand/icon-'))->toBeTrue($path);
    }
});

// ---------------------------------------------------------------------------------------------
// The icons
// ---------------------------------------------------------------------------------------------

test('every icon exists, PNG, with the size it declares; 192 and 512 for "any" and for "maskable"', function () {
    $icons = wamManifest()['icons'];
    $declared = [];

    foreach ($icons as $icon) {
        expect(array_keys($icon))->toBe(['src', 'sizes', 'purpose', 'type'])
            ->and($icon['type'])->toBe('image/png')
            ->and(file_exists(public_path(ltrim($icon['src'], '/'))))->toBeTrue($icon['src']);

        $size = getimagesize(public_path(ltrim($icon['src'], '/')));
        expect($size)->not->toBeFalse()
            ->and($size['mime'])->toBe('image/png')
            ->and("{$size[0]}x{$size[1]}")->toBe($icon['sizes'], $icon['src']);
        $declared[] = "{$icon['purpose']} {$icon['sizes']}";
    }

    expect($declared)->toContain('any 192x192', 'any 512x512', 'maskable 192x192', 'maskable 512x512');
});

test('a maskable icon is opaque to the corners, and everything but the brown lies in the safe circle of 40%', function () {
    $maskable = array_filter(wamManifest()['icons'], fn (array $icon) => $icon['purpose'] === 'maskable');
    expect($maskable)->toHaveCount(2);

    foreach ($maskable as $icon) {
        $image = wamImage($icon['src']);
        $size = imagesx($image);
        $centre = $size / 2;
        $radius = $size * 0.4;
        [$transparent, $outside, $top, $bottom] = [0, 0, $size, -1];

        for ($y = 0; $y < $size; $y++) {
            for ($x = 0; $x < $size; $x++) {
                [$r, $g, $b, $alpha] = wamPixel($image, $x, $y);
                $transparent += $alpha > 0 ? 1 : 0;
                // Brown: the square itself (GD may move a channel by one when it blends).
                $brown = abs($r - BrandColours::BROWN[0]) <= 2 && abs($g - BrandColours::BROWN[1]) <= 2 && abs($b - BrandColours::BROWN[2]) <= 2;

                if (! $brown) {
                    // The farthest corner of the pixel from the centre.
                    $dx = max(abs($x - $centre), abs($x + 1 - $centre));
                    $dy = max(abs($y - $centre), abs($y + 1 - $centre));
                    $outside += hypot($dx, $dy) > $radius ? 1 : 0;
                    [$top, $bottom] = [min($top, $y), max($bottom, $y)];
                }
            }
        }

        expect($transparent)->toBe(0, "{$icon['src']}: transparent pixels")
            ->and($outside)->toBe(0, "{$icon['src']}: outside the safe circle")
            // The pin: 56% of the side (MASKABLE_PIN), a pixel more or less for the soft edge.
            ->and(abs(($bottom - $top + 1) - round($size * 0.56)))->toBeLessThanOrEqual(2, "{$icon['src']}: pin of ".($bottom - $top + 1).' px');
    }
});

test('the "any" icons keep the round corners of the favicons: transparent in the corner, brown in the middle of an edge', function () {
    foreach (array_filter(wamManifest()['icons'], fn (array $icon) => $icon['purpose'] === 'any') as $icon) {
        $image = wamImage($icon['src']);
        $size = imagesx($image);

        expect(wamPixel($image, 0, 0)[3])->toBe(127, $icon['src'])
            ->and(array_slice(wamPixel($image, intdiv($size, 2), 0), 0, 3))->toBe(BrandColours::BROWN, $icon['src']);
    }
});

// ---------------------------------------------------------------------------------------------
// The pages
// ---------------------------------------------------------------------------------------------

test('public, private and provider pages link the manifest and the theme colours of both themes', function () {
    $booking = Booking::factory()->create();
    $user = $booking->provider->user;
    $tags = [
        '<link rel="manifest" href="/manifest.webmanifest">',
        '<meta name="theme-color" media="(prefers-color-scheme: light)" content="'.wamToken('hero').'">',
        '<meta name="theme-color" media="(prefers-color-scheme: dark)" content="'.wamToken('hero', 'dark').'">',
    ];

    $pages = [
        'home' => $this->get('/'),
        'privacy' => $this->get('/privacy'),
        'private booking' => $this->get('/bookings/'.$booking->public_token),
        'login' => $this->get('/login'),
    ];
    $pages['provider bookings'] = $this->actingAs($user)->get('/provider/bookings');

    foreach ($pages as $name => $response) {
        $html = (string) $response->assertOk()->getContent();

        foreach ($tags as $tag) {
            expect(substr_count($html, $tag))->toBe(1, "{$name}: {$tag}");
        }
        expect(substr_count($html, 'rel="manifest"'))->toBe(1, $name);
    }
});

test('the colours of the tags are those of the constants, the brown of the dark theme its own token', function () {
    expect(BrandColours::hex(BrandColours::BROWN))->toBe(wamToken('hero'))
        ->and(BrandColours::hex(BrandColours::BROWN_DARK))->toBe(wamToken('hero', 'dark'))
        ->and(BrandColours::hex([0x0A, 0xBC, 0x00]))->toBe('#0ABC00');
    expect((string) file_get_contents(resource_path('views/partials/web-app.blade.php')))
        ->toContain('$brand::hex($brand::BROWN)')
        ->toContain('$brand::hex($brand::BROWN_DARK)')
        ->not->toMatch('/#[0-9a-fA-F]{6}\b/');
});

// ---------------------------------------------------------------------------------------------
// No service worker: the private pages stay as they are
// ---------------------------------------------------------------------------------------------

test('no service worker: no file in public/ and no registration in the code of the pages', function () {
    $public = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(public_path(), FilesystemIterator::SKIP_DOTS)) as $file) {
        $path = str_replace(public_path().'/', '', $file->getPathname());
        if (! str_starts_with($path, 'build/') && ! str_starts_with($path, 'storage/')) {
            $public[] = $path;
        }
    }
    foreach ($public as $path) {
        expect(preg_match('/(^|\/)(sw|service-?worker|workbox[^\/]*)\.(js|mjs)$/i', $path))->toBe(0, $path);
    }

    $sources = '';
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(resource_path(), FilesystemIterator::SKIP_DOTS)) as $file) {
        if (preg_match('/\.(tsx?|jsx?|php)$/', $file->getPathname()) === 1) {
            $sources .= (string) file_get_contents($file->getPathname());
        }
    }
    expect($sources)->not->toContain('serviceWorker')->not->toContain('navigator.serviceWorker');

    // Nor a plugin that would make one at build time.
    $package = (string) file_get_contents(base_path('package.json'));
    expect($package)->not->toContain('vite-plugin-pwa')->not->toContain('workbox');
    expect((string) file_get_contents(base_path('vite.config.ts')))->not->toMatch('/pwa|workbox|serviceWorker/i');
});

test('the private pages keep no-store, noindex and their Referrer-Policy', function () {
    $booking = Booking::factory()->create();

    $this->get('/bookings/'.$booking->public_token)
        ->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
        ->assertHeader('Referrer-Policy', 'no-referrer');

    $this->actingAs($booking->provider->user)->get('/provider/bookings')
        ->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow');
});
