<?php

// Colour contrast (issue #55, phase 1). The colours are read where they are written: the tokens of
// resources/css/app.css, Tailwind's palette in its theme.css, the ring opacities in components/ui.
// WCAG thresholds: 4.5:1 for normal text, 3:1 for the boundaries of a field and the focus ring.

/**
 * The opacity (0-1) of a ring class in a component of the kit, for example "focus-visible:ring-ring/".
 */
function accOpacity(string $component, string $class): float
{
    $source = (string) file_get_contents(resource_path("js/components/ui/{$component}.tsx"));
    expect(preg_match('#(?<![\w:-])'.preg_quote($class, '#').'(\d+)\b#', $source, $match))->toBe(1, "No {$class} in {$component}.tsx");

    return (int) $match[1] / 100;
}

/**
 * Every pair checked, in one theme: [what, foreground (sRGB), background (sRGB), threshold].
 *
 * @return list<array{0: string, 1: array{0: float, 1: float, 2: float}, 2: array{0: float, 1: float, 2: float}, 3: float}>
 */
function accPairs(string $theme): array
{
    $t = accTokens($theme);
    $light = $theme === 'light';
    $white = [1.0, 0.0, 0.0];
    $background = accSrgb($t['background']);
    $ring = $t['ring'];
    $pairs = [
        ['text on the background', accSrgb($t['foreground']), $background, 4.5],
        ['muted text (help, city, placeholder) on the background', accSrgb($t['muted-foreground']), $background, 4.5],
        ['primary button', accSrgb($t['primary-foreground']), accSrgb($t['primary']), 4.5],
        ['secondary button and badge', accSrgb($t['secondary-foreground']), accSrgb($t['secondary']), 4.5],
        // button.tsx and badge.tsx: "bg-destructive text-white".
        ['destructive button: white text', accSrgb($white), accSrgb($t['destructive']), 4.5],
        // input-error.tsx: "text-red-600 dark:text-red-400".
        ['error text', accSrgb(accTailwind($light ? 'red-600' : 'red-400')), $background, 4.5],
        // login, forgot-password, verify-email, settings/profile: "text-green-700 dark:text-green-600".
        ['status text (green)', accSrgb(accTailwind($light ? 'green-700' : 'green-600')), $background, 4.5],
        // delete-user.tsx, the warning: "bg-red-50 … text-red-700", dark "dark:bg-red-700/10 …
        // dark:text-red-100".
        $light
            ? ['delete-account warning', accSrgb(accTailwind('red-700')), accSrgb(accTailwind('red-50')), 4.5]
            : ['delete-account warning', accSrgb(accTailwind('red-100')), accOver(accTailwind('red-700'), 0.1, $t['background']), 4.5],
        // appearance-tabs.tsx, a tab not chosen: "text-muted-foreground" on "bg-muted".
        ['appearance tab', accSrgb($t['muted-foreground']), accSrgb($t['muted']), 4.5],
        ['border of the fields (border-input)', accSrgb($t['input']), $background, 3.0],
        ['border of a field with the focus (border-ring)', accSrgb($ring), $background, 3.0],
        ['sidebar focus ring (ring-sidebar-ring)', accSrgb($t['sidebar-ring']), accSrgb($t['sidebar']), 3.0],

        // The palette of the visual identity (Sprint 8): green for links and titles, the cards, the
        // grey and blue surfaces, the yellow, the red decor, the sidebar.
        ['links and the name of the site (text-primary) on the background', accSrgb($t['primary']), $background, 4.5],
        ['the name of the site in the sidebar', accSrgb($t['primary']), accSrgb($t['sidebar']), 4.5],
        ['text on the cards', accSrgb($t['card-foreground']), accSrgb($t['card']), 4.5],
        ['muted text on the grey surfaces', accSrgb($t['muted-foreground']), accSrgb($t['muted']), 4.5],
        ['active item (accent)', accSrgb($t['accent-foreground']), accSrgb($t['accent']), 4.5],
        ['outline badge: text on the background', accSrgb($t['foreground']), $background, 4.5],
        ['text on the yellow (highlight)', accSrgb($t['highlight-foreground']), accSrgb($t['highlight']), 4.5],
        ['text on the light blue (info)', accSrgb($t['info-foreground']), accSrgb($t['info']), 4.5],
        ['border of the light blue (info-border)', accSrgb($t['info-border']), accSrgb($t['info']), 3.0],
        ['red decor line on the background', accSrgb($t['decor']), $background, 3.0],
        ['sidebar: text', accSrgb($t['sidebar-foreground']), accSrgb($t['sidebar']), 4.5],
        ['sidebar: active item', accSrgb($t['sidebar-accent-foreground']), accSrgb($t['sidebar-accent']), 4.5],
        ['error text on the cards', accSrgb(accTailwind($light ? 'red-600' : 'red-400')), accSrgb($t['card']), 4.5],
        ['status text on the cards', accSrgb(accTailwind($light ? 'green-700' : 'green-600')), accSrgb($t['card']), 4.5],
    ];

    // The focus ring over the yellow and the light blue, as over the other surfaces.
    foreach (['highlight', 'info'] as $surface) {
        $opacity = accOpacity('input', 'focus-visible:ring-ring/');
        $pairs[] = ["focus ring ({$opacity}) over {$surface}", accOver($ring, $opacity, $t[$surface]), accSrgb($t[$surface]), 3.0];
    }

    // The focus ring as the components really draw it: the ring colour with their opacity, around
    // the field, over the page, the sidebar and the grey surfaces.
    foreach (['input', 'select', 'button', 'checkbox', 'textarea', 'badge'] as $component) {
        $opacity = accOpacity($component, 'focus-visible:ring-ring/');

        foreach (['background', 'sidebar', 'muted'] as $surface) {
            $pairs[] = ["focus ring of {$component} ({$opacity}) over {$surface}", accOver($ring, $opacity, $t[$surface]), accSrgb($t[$surface]), 3.0];
        }
    }

    // The red ring of a destructive button and of a field marked aria-invalid.
    foreach (['input', 'select', 'checkbox', 'button'] as $component) {
        [$color, $class] = $light ? [$t['destructive'], 'aria-invalid:ring-destructive/'] : [$t['destructive-foreground'], 'dark:aria-invalid:ring-destructive-foreground/'];
        $opacity = accOpacity($component, $class);
        $pairs[] = ["red focus ring of an invalid {$component} ({$opacity})", accOver($color, $opacity, $t['background']), $background, 3.0];
    }

    [$color, $class] = $light ? [$t['destructive'], 'focus-visible:ring-destructive/'] : [$t['destructive-foreground'], 'dark:focus-visible:ring-destructive-foreground/'];
    $opacity = accOpacity('button', $class);
    $pairs[] = ["focus ring of a destructive button ({$opacity})", accOver($color, $opacity, $t['background']), $background, 3.0];

    // The green band at the top of the home page (docs/home.md), as lib/home-hero.ts colours it:
    // the white text on the green, the edge of the fields (their own background) and of the light
    // buttons against the green, the buttons' green text (also on hover, at 90%), the white focus
    // ring over the green.
    $hero = accSrgb($t['hero']);
    $pairs[] = ['home band: text', accSrgb($t['hero-foreground']), $hero, 4.5];
    $pairs[] = ['home band: edge of the fields (bg-background)', $background, $hero, 3.0];
    $pairs[] = ['home band: text of the fields', accSrgb($t['foreground']), $background, 4.5];
    $pairs[] = ['home band: edge of the buttons (bg-hero-foreground)', accSrgb($t['hero-foreground']), $hero, 3.0];
    $pairs[] = ['home band: text of the buttons', $hero, accSrgb($t['hero-foreground']), 4.5];
    $pairs[] = ['home band: text of the buttons on hover (hover:bg-hero-foreground/90)', $hero, accOver($t['hero-foreground'], 0.9, $t['hero']), 4.5];
    $pairs[] = ['home band: focus ring and dots (hero-foreground)', accSrgb($t['hero-foreground']), $hero, 3.0];
    // The focus ring of the fields is white too: against the white field it is the field's own
    // focus border (border-ring) that shows, measured above on the background.

    return $pairs;
}

test('every colour pair reaches its WCAG threshold, in the light and in the dark theme', function (string $theme) {
    foreach (accPairs($theme) as [$what, $foreground, $background, $threshold]) {
        $ratio = accRatio($foreground, $background);

        expect($ratio)->toBeGreaterThanOrEqual($threshold, sprintf('%s, %s theme: %.2f:1 (needed %.1f:1)', $what, $theme, $ratio, $threshold));
    }
})->with(['light', 'dark']);

test('the band of the home page uses the tokens measured above, never red', function () {
    $source = (string) file_get_contents(resource_path('js/lib/home-hero.ts'));

    expect($source)
        ->toContain("band: 'bg-hero text-hero-foreground',")
        ->toContain("field: 'bg-background text-foreground focus-visible:ring-hero-foreground',")
        ->toContain("button: 'bg-hero-foreground text-hero hover:bg-hero-foreground/90 focus-visible:ring-hero-foreground focus-visible:ring-offset-2 focus-visible:ring-offset-hero',")
        ->toContain("dot: 'border-hero-foreground',")
        ->toContain("dotCurrent: 'bg-hero-foreground',")
        ->toContain("ring: 'focus-visible:ring-hero-foreground',");

    // Every colour class of the band, the carousel and the search is one of the measured tokens.
    $sources = $source;
    foreach (['home-hero', 'home-carousel', 'home-search'] as $component) {
        $sources .= (string) file_get_contents(resource_path("js/components/home/{$component}.tsx"));
    }
    preg_match_all('/(?<![\w-])(?:hover:|focus-visible:)?(?:ring-offset|bg|text|border|ring)-([a-z]+(?:-[a-z]+)*)(?:\/\d+)?(?![\w-])/', $sources, $colours);
    $tokens = array_unique($colours[1]);
    $sizes = ['xl', 'sm', 'base', '3xl', '4xl', 'lg'];
    expect(array_values(array_diff($tokens, ['hero', 'hero-foreground', 'background', 'foreground', 'muted', 'input', ...$sizes])))->toBe([])
        ->and($sources)->not->toMatch('/\b(bg|text|border)-(decor|destructive|primary|red-\d)/');

    // Dark green in both themes, white text: not "primary", which is light green in the dark theme.
    expect(accTokens('light')['hero'][0])->toBeLessThan(0.55)
        ->and(accTokens('dark')['hero'][0])->toBeLessThan(0.55)
        ->and(accTokens('light')['hero-foreground'][0])->toBeGreaterThan(0.95)
        ->and(accTokens('dark')['hero-foreground'][0])->toBeGreaterThan(0.95);
});

test('the conversion gives the known ratios: black on white 21:1, a colour on itself 1:1', function () {
    expect(accRatio(accSrgb([0.0, 0.0, 0.0]), accSrgb([1.0, 0.0, 0.0])))->toEqualWithDelta(21.0, 0.01)
        ->and(accRatio(accSrgb([0.556, 0.0, 0.0]), accSrgb([0.556, 0.0, 0.0])))->toEqualWithDelta(1.0, 0.0001)
        // Measured with the browser's colour tools: neutral-500 on white.
        ->and(accRatio(accSrgb([0.556, 0.0, 0.0]), accSrgb([1.0, 0.0, 0.0])))->toEqualWithDelta(4.73, 0.02);
});

test('the old tokens and the old ring opacities do not reach the thresholds', function () {
    // The values before issue #55: the test above must fail if one of them comes back.
    $white = [1.0, 0.0, 0.0];
    $dark = [0.145, 0.0, 0.0];

    expect(accRatio(accSrgb([0.922, 0.0, 0.0]), accSrgb($white)))->toBeLessThan(3.0)
        ->and(accRatio(accOver([0.87, 0.0, 0.0], 0.5, $white), accSrgb($white)))->toBeLessThan(3.0)
        ->and(accRatio(accSrgb([0.87, 0.0, 0.0]), accSrgb($white)))->toBeLessThan(3.0)
        ->and(accRatio(accSrgb([0.269, 0.0, 0.0]), accSrgb($dark)))->toBeLessThan(3.0)
        ->and(accRatio(accOver([0.439, 0.0, 0.0], 0.5, $dark), accSrgb($dark)))->toBeLessThan(3.0)
        ->and(accRatio(accOver([0.577, 0.245, 27.325], 0.2, $white), accSrgb($white)))->toBeLessThan(3.0)
        ->and(accRatio(accSrgb(accTailwind('green-600')), accSrgb($white)))->toBeLessThan(4.5)
        // The delete-account warning and the appearance tabs before the final check (axe: 4.36
        // and 4.34).
        ->and(accRatio(accSrgb(accTailwind('red-600')), accSrgb(accTailwind('red-50'))))->toBeLessThan(4.5)
        ->and(accRatio(accSrgb(accTailwind('neutral-500')), accSrgb(accTailwind('neutral-100'))))->toBeLessThan(4.5);
});

test('the delete-account warning and the appearance tabs use the colours measured above', function () {
    expect((string) file_get_contents(resource_path('js/components/delete-user.tsx')))
        ->toContain('bg-red-50 p-4 dark:border-red-200/10 dark:bg-red-700/10')
        ->toContain('text-red-700 dark:text-red-100')
        ->not->toContain('text-red-600');
    expect((string) file_get_contents(resource_path('js/components/appearance-tabs.tsx')))
        ->toContain("'inline-flex gap-1 rounded-lg bg-muted p-1'")
        ->toContain("'text-muted-foreground hover:bg-accent hover:text-accent-foreground'")
        ->not->toContain('neutral-');
});

test('the borders of cards and separators keep their light colour', function () {
    // Decorative: no threshold. The fields (--input) have their own, darker border.
    expect(accTokens('light')['border'])->toBe([0.91, 0.02, 225.0])
        ->and(accTokens('dark')['border'])->toBe([0.31, 0.03, 245.0]);
});

test('no light green-600 text is left in the pages: the status messages use green-700', function () {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(resource_path('js'), FilesystemIterator::SKIP_DOTS));

    foreach ($iterator as $file) {
        if (str_ends_with($file->getPathname(), '.tsx')) {
            // Only the dark theme keeps green-600, where it reaches 6:1.
            expect(preg_match('/(?<!dark:)text-green-600/', (string) file_get_contents($file->getPathname())))->toBe(0, "A light green-600 text in {$file->getPathname()}");
        }
    }

    foreach (['auth/login', 'auth/forgot-password', 'auth/verify-email', 'settings/profile'] as $page) {
        expect((string) file_get_contents(resource_path("js/pages/{$page}.tsx")))->toContain('text-green-700 dark:text-green-600');
    }
});
