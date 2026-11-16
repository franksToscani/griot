<?php

// The fonts of palette C (docs/accessibility.md, "Tipografia"): Atkinson Hyperlegible Next for the
// text, Fraunces for the titles, Noto Sans only as their fallback. All from the project's own
// node_modules (fontsource), never from an external service.

function tySource(string $path): string
{
    return (string) file_get_contents(base_path($path));
}

/**
 * The unicode ranges of one subset of a fontsource package: list of [first, last] code points.
 *
 * @return list<array{int, int}>
 */
function tyRanges(string $package, string $subset): array
{
    $css = tySource("node_modules/@fontsource-variable/{$package}/wght.css");
    preg_match('#/\* [a-z-]+-'.preg_quote($subset, '#').'-wght-normal \*/\s*@font-face\s*\{.*?unicode-range:\s*([^;]+);#s', $css, $face);
    expect($face)->not->toBeEmpty("{$package}: no {$subset} subset");

    return array_map(function (string $part): array {
        [$first, $last] = array_pad(explode('-', str_replace('U+', '', trim($part))), 2, null);

        return [hexdec($first), hexdec($last ?? $first)];
    }, explode(',', $face[1]));
}

/**
 * @param  list<array{int, int}>  $ranges
 */
function tyCovers(array $ranges, int $codePoint): bool
{
    foreach ($ranges as [$first, $last]) {
        if ($codePoint >= $first && $codePoint <= $last) {
            return true;
        }
    }

    return false;
}

test('the three families, from node_modules, in this order: text, titles, fallback', function () {
    $css = tySource('resources/css/app.css');
    preg_match_all("#@import '(@fontsource[^']+)';#", $css, $imports);

    expect($imports[1])->toBe([
        '@fontsource-variable/atkinson-hyperlegible-next/wght.css',
        '@fontsource-variable/fraunces/wght.css',
        '@fontsource-variable/noto-sans/wght.css',
    ]);

    // Noto Sans only after the font of the text and of the titles: a fallback, never first.
    expect($css)->toMatch("/--font-sans:\\s*'Atkinson Hyperlegible Next Variable',\\s*'Noto Sans Variable',/")
        ->toMatch("/--font-heading:\\s*'Fraunces Variable',\\s*'Noto Sans Variable',/")
        ->not->toMatch("/--font-(sans|heading):\\s*'Noto Sans Variable'/");
});

test('the titles take the font of the titles in one place: h1, h2 and h3 in the base layer', function () {
    expect(tySource('resources/css/app.css'))->toMatch('/@layer base \{[\s\S]*?h1,\s*h2,\s*h3\s*\{\s*font-family: var\(--font-heading\);\s*\}/');

    // Never a font chosen component by component.
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(resource_path('js'), FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        $path = $file->getPathname();
        if (preg_match('/\.tsx?$/', $path) === 1 && preg_match('#/resources/js/(routes|actions|wayfinder)/#', $path) !== 1) {
            // font-mono stays for code (the names of the cookies on the privacy page).
            expect((string) file_get_contents($path))->not->toMatch('/\bfont-(heading|serif)\b|fontFamily/', $path);
        }
    }
});

test('no font from an external service, at build time or in the browser', function () {
    foreach (['resources/css/app.css', 'resources/views/app.blade.php', 'resources/views/errors/layout.blade.php', 'vite.config.ts'] as $path) {
        expect(tySource($path))
            ->not->toMatch('#https?://#')
            ->not->toContain('fonts.googleapis')
            ->not->toContain('fonts.bunny')
            ->not->toContain('use.typekit');
    }
});

test('the packages are dependencies, under the SIL Open Font License, and cover the weights used', function () {
    $package = json_decode(tySource('package.json'), true);

    foreach (['atkinson-hyperlegible-next' => [400, 500, 600], 'fraunces' => [600, 700], 'noto-sans' => [400, 700]] as $name => $weights) {
        expect($package['dependencies'])->toHaveKey("@fontsource-variable/{$name}");
        expect(tySource("node_modules/@fontsource-variable/{$name}/LICENSE"))->toContain('SIL Open Font License, Version 1.1');

        preg_match('/font-weight:\s*(\d+)\s+(\d+);/', tySource("node_modules/@fontsource-variable/{$name}/wght.css"), $range);
        foreach ($weights as $weight) {
            expect($weight)->toBeGreaterThanOrEqual((int) $range[1])->toBeLessThanOrEqual((int) $range[2]);
        }
    }
});

test('an Italian, French or English page needs only the latin subsets of Atkinson and Fraunces', function () {
    $text = tyRanges('atkinson-hyperlegible-next', 'latin');
    $titles = tyRanges('fraunces', 'latin');

    foreach (['it', 'fr', 'en'] as $locale) {
        // The texts of the console (console.*) are printed in a terminal, never in a page.
        $translations = array_filter(
            json_decode((string) file_get_contents(lang_path("{$locale}.json")), true),
            fn (string $key) => ! str_starts_with($key, 'console.'),
            ARRAY_FILTER_USE_KEY,
        );
        $missing = [];

        foreach (mb_str_split(implode('', $translations)) as $letter) {
            $codePoint = mb_ord($letter);
            if (! tyCovers($text, $codePoint) || ! tyCovers($titles, $codePoint)) {
                $missing[$letter] = sprintf('U+%04X', $codePoint);
            }
        }

        expect($missing)->toBe([], "{$locale}: letters outside the latin subsets");
    }
});

test('Noto Sans is downloaded only for the letters the others lack: ẹ ọ outside Atkinson, in Noto', function () {
    $atkinson = [...tyRanges('atkinson-hyperlegible-next', 'latin'), ...tyRanges('atkinson-hyperlegible-next', 'latin-ext')];
    $noto = [...tyRanges('noto-sans', 'latin-ext'), ...tyRanges('noto-sans', 'vietnamese')];

    foreach (['ẹ', 'ọ'] as $letter) {
        expect(tyCovers($atkinson, mb_ord($letter)))->toBeFalse($letter)
            ->and(tyCovers($noto, mb_ord($letter)))->toBeTrue($letter);
    }

    // Every letter of Noto's latin subset is in Atkinson: a normal page never asks Noto.
    foreach (tyRanges('noto-sans', 'latin') as [$first, $last]) {
        for ($codePoint = $first; $codePoint <= $last; $codePoint++) {
            expect(tyCovers($atkinson, $codePoint))->toBeTrue(sprintf('U+%04X', $codePoint));
        }
    }
});
