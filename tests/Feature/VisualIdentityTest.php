<?php

// Griot's visual identity (Sprint 8): the font inside the project, the name of the site, the small
// mark and the favicons. The colours are checked in AccessibilityContrastTest.

function vidSource(string $path): string
{
    return (string) file_get_contents(base_path($path));
}

/**
 * Width and height of a PNG, from its IHDR chunk.
 *
 * @return array{0: int, 1: int}
 */
function vidPngSize(string $png): array
{
    expect(substr($png, 0, 8))->toBe("\x89PNG\r\n\x1a\n");
    $size = unpack('Nwidth/Nheight', substr($png, 16, 8));
    expect($size)->toBeArray();

    return [(int) $size['width'], (int) $size['height']];
}

// The path of the "G" in the small mark and in public/favicon.svg.
const VID_G_PATH = 'M41.9 22.1A14 14 0 1 0 46 32H33';

// ---------------------------------------------------------------------------------------------
// Font
// ---------------------------------------------------------------------------------------------

test('the font is inside the project: no external service, at build time or in the browser', function () {
    foreach (['vite.config.ts', 'resources/views/app.blade.php'] as $path) {
        expect(vidSource($path))
            ->not->toContain('bunny')
            ->not->toContain('@fonts')
            ->not->toContain('fonts.googleapis')
            ->not->toContain('laravel-vite-plugin/fonts');
    }

    $css = vidSource('resources/css/app.css');
    expect($css)
        ->toContain("@import '@fontsource-variable/noto-sans/wght.css';")
        ->toMatch("/--font-sans:\s*'Noto Sans Variable',/")
        ->not->toContain('Instrument Sans');

    // In the dependencies, and under the SIL Open Font License.
    $package = json_decode(vidSource('package.json'), true);
    expect($package['dependencies'])->toHaveKey('@fontsource-variable/noto-sans');
    expect(vidSource('node_modules/@fontsource-variable/noto-sans/LICENSE'))->toContain('SIL Open Font License, Version 1.1');
});

test('the subsets of the font cover the letters of the names: ɛ ɔ ŋ ṣ in latin-ext, ẹ ọ in vietnamese', function () {
    $css = vidSource('node_modules/@fontsource-variable/noto-sans/wght.css');
    preg_match_all('#/\* noto-sans-([a-z-]+)-wght-normal \*/\s*@font-face\s*\{.*?unicode-range:\s*([^;]+);#s', $css, $faces, PREG_SET_ORDER);

    $covers = function (string $subset, string $letter) use ($faces): bool {
        $code = mb_ord($letter);

        foreach ($faces as [, $name, $ranges]) {
            if ($name !== $subset) {
                continue;
            }

            foreach (explode(',', $ranges) as $range) {
                [$from, $to] = array_pad(explode('-', substr(trim($range), 2)), 2, null);

                if ($code >= hexdec($from) && $code <= hexdec($to ?? $from)) {
                    return true;
                }
            }
        }

        return false;
    };

    foreach (['ɛ', 'ɔ', 'ŋ', 'ṣ'] as $letter) {
        expect($covers('latin-ext', $letter))->toBeTrue("{$letter} not in latin-ext");
    }
    foreach (['ẹ', 'ọ'] as $letter) {
        expect($covers('vietnamese', $letter))->toBeTrue("{$letter} not in vietnamese");
    }
    // The imported file holds both subsets.
    expect($faces)->not->toBeEmpty();
});

// ---------------------------------------------------------------------------------------------
// Errors and statuses keep today's colours
// ---------------------------------------------------------------------------------------------

test('errors, statuses and destructive actions keep the colours they had before the new palette', function () {
    expect(vidSource('resources/js/components/input-error.tsx'))->toContain('text-red-600 dark:text-red-400');
    expect(vidSource('resources/js/components/delete-user.tsx'))->toContain('text-red-700 dark:text-red-100');

    $css = vidSource('resources/css/app.css');
    preg_match('/^:root \{(.*?)^\}/ms', $css, $light);
    preg_match('/^\.dark \{(.*?)^\}/ms', $css, $dark);
    expect($light[1])
        ->toContain('--destructive: oklch(0.577 0.245 27.325);')
        ->toContain('--destructive-foreground: oklch(0.577 0.245 27.325);');
    expect($dark[1])
        ->toContain('--destructive: oklch(0.396 0.141 25.723);')
        ->toContain('--destructive-foreground: oklch(0.637 0.237 25.331);');
});

test('the orange decor is only the line under the public header', function () {
    $uses = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(resource_path('js'), FilesystemIterator::SKIP_DOTS));

    foreach ($iterator as $file) {
        if (str_ends_with($file->getPathname(), '.tsx') && str_contains((string) file_get_contents($file->getPathname()), '-decor')) {
            $uses[] = str_replace(resource_path('js').'/', '', $file->getPathname());
        }
    }

    sort($uses);
    // Since the warm palette, no longer under the name of the site: one line, one place.
    expect($uses)->toBe(['layouts/public-layout.tsx']);
    expect(vidSource('resources/js/layouts/public-layout.tsx'))->toContain('<header className="border-b-2 border-decor">');
});

// ---------------------------------------------------------------------------------------------
// Name of the site, small mark, favicons
// ---------------------------------------------------------------------------------------------

test('the name of the site is real text, bold and brown (text-primary), from the app name', function () {
    expect(vidSource('resources/js/components/app-logo.tsx'))
        ->toContain('const { name } = usePage().props;')
        ->toMatch('/<span className="[^"]*\bfont-bold\b[^"]*\btext-primary\b[^"]*">\s*\{name\}\s*<\/span>/')
        ->not->toMatch('/\b(?:border|bg|text)-decor\b/');
    // Brown in the light theme: dark, between red and yellow.
    [$lightness, $chroma, $hue] = accTokens('light')['primary'];
    expect($lightness)->toBeLessThan(0.4)
        ->and($chroma)->toBeGreaterThan(0.04)
        ->and($hue)->toBeGreaterThanOrEqual(30.0)->toBeLessThanOrEqual(60.0);
    expect(vidSource('resources/js/components/app-logo.tsx'))
        // Collapsed sidebar: hidden on screen, still the name of the link.
        ->toContain('group-data-[collapsible=icon]:sr-only');
});

test('the small mark is a "G" on a square of the buttons\' colour (primary), not the Laravel logo', function () {
    expect(vidSource('resources/js/components/app-logo-icon.tsx'))
        ->toContain(VID_G_PATH)
        ->toContain('className="fill-primary"')
        ->toContain('className="stroke-primary-foreground"')
        ->toContain('aria-hidden="true"')
        // The first point of the Laravel cube.
        ->not->toContain('M17.2 5.63325');
});

test('the favicons are Griot\'s, in the sizes the page points to', function () {
    $svg = vidSource('public/favicon.svg');
    expect($svg)->toContain(VID_G_PATH)->toContain('fill="#005f48"')
        // The first point of the Laravel favicon.
        ->not->toContain('M162.041');

    expect(vidPngSize(vidSource('public/apple-touch-icon.png')))->toBe([180, 180]);

    // An ICO with three PNG images: 16, 32 and 48 px.
    $ico = vidSource('public/favicon.ico');
    $header = unpack('vreserved/vtype/vcount', substr($ico, 0, 6));
    expect($header)->toBe(['reserved' => 0, 'type' => 1, 'count' => 3]);
    $sizes = [];

    for ($i = 0; $i < 3; $i++) {
        $entry = unpack('Cwidth/Cheight/x2/vplanes/vbits/Vbytes/Voffset', substr($ico, 6 + 16 * $i, 16));
        expect($entry)->toBeArray();
        $sizes[] = vidPngSize(substr($ico, (int) $entry['offset'], (int) $entry['bytes']));
    }

    expect($sizes)->toBe([[16, 16], [32, 32], [48, 48]]);

    expect(vidSource('resources/views/app.blade.php'))
        ->toContain('<link rel="icon" href="/favicon.ico" sizes="any">')
        ->toContain('<link rel="icon" href="/favicon.svg" type="image/svg+xml">')
        ->toContain('<link rel="apple-touch-icon" href="/apple-touch-icon.png">');
});
