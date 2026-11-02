<?php

// Colour contrast (issue #55, phase 1). The colours are read where they are written: the tokens of
// resources/css/app.css, Tailwind's palette in its theme.css, the ring opacities in components/ui.
// WCAG thresholds: 4.5:1 for normal text, 3:1 for the boundaries of a field and the focus ring.

/**
 * The oklch tokens of a theme: "light" is the :root block, "dark" the .dark block.
 *
 * @return array<string, array{0: float, 1: float, 2: float}>
 */
function accTokens(string $theme): array
{
    $css = (string) file_get_contents(resource_path('css/app.css'));
    $selector = $theme === 'light' ? ':root' : '.dark';
    expect(preg_match('/^'.preg_quote($selector, '/').' \{(.*?)^\}/ms', $css, $block))->toBe(1, "No {$selector} block in app.css");

    return accParse($block[1]);
}

/**
 * A colour of Tailwind's palette, for example "green-700".
 *
 * @return array{0: float, 1: float, 2: float}
 */
function accTailwind(string $color): array
{
    $theme = accParse((string) file_get_contents(base_path('node_modules/tailwindcss/theme.css')));

    return $theme["color-{$color}"];
}

/**
 * The "--name: oklch(L C H)" declarations of a CSS text; L may be written as a percentage.
 *
 * @return array<string, array{0: float, 1: float, 2: float}>
 */
function accParse(string $css): array
{
    preg_match_all('/--([a-z0-9-]+):\s*oklch\(([\d.]+)(%?)\s+([\d.]+)\s+([\d.]+)\)/', $css, $matches, PREG_SET_ORDER);
    $colors = [];

    foreach ($matches as [, $name, $lightness, $percent, $chroma, $hue]) {
        $colors[$name] = [(float) $lightness / ($percent === '%' ? 100 : 1), (float) $chroma, (float) $hue];
    }

    return $colors;
}

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
 * oklch to gamma-encoded sRGB (0-1 per channel), clipped to the gamut as the browser shows it.
 *
 * @param  array{0: float, 1: float, 2: float}  $oklch
 * @return array{0: float, 1: float, 2: float}
 */
function accSrgb(array $oklch): array
{
    [$l, $c, $h] = $oklch;
    $a = $c * cos(deg2rad($h));
    $b = $c * sin(deg2rad($h));

    $l1 = ($l + 0.3963377774 * $a + 0.2158037573 * $b) ** 3;
    $m1 = ($l - 0.1055613458 * $a - 0.0638541728 * $b) ** 3;
    $s1 = ($l - 0.0894841775 * $a - 1.2914855480 * $b) ** 3;

    $linear = [
        4.0767416621 * $l1 - 3.3077115913 * $m1 + 0.2309699292 * $s1,
        -1.2684380046 * $l1 + 2.6097574011 * $m1 - 0.3413193965 * $s1,
        -0.0041960863 * $l1 - 0.7034186147 * $m1 + 1.7076147010 * $s1,
    ];

    return array_map(function (float $channel): float {
        $channel = min(1.0, max(0.0, $channel));

        return $channel <= 0.0031308 ? 12.92 * $channel : 1.055 * $channel ** (1 / 2.4) - 0.055;
    }, $linear);
}

/**
 * A colour with an opacity over a background, mixed in sRGB as the browser does.
 *
 * @param  array{0: float, 1: float, 2: float}  $color
 * @param  array{0: float, 1: float, 2: float}  $background
 * @return array{0: float, 1: float, 2: float}
 */
function accOver(array $color, float $opacity, array $background): array
{
    $front = accSrgb($color);
    $back = accSrgb($background);

    return [0 => $opacity * $front[0] + (1 - $opacity) * $back[0], 1 => $opacity * $front[1] + (1 - $opacity) * $back[1], 2 => $opacity * $front[2] + (1 - $opacity) * $back[2]];
}

/**
 * The WCAG contrast ratio of two gamma-encoded sRGB colours.
 *
 * @param  array{0: float, 1: float, 2: float}  $first
 * @param  array{0: float, 1: float, 2: float}  $second
 */
function accRatio(array $first, array $second): float
{
    $luminance = function (array $srgb): float {
        [$r, $g, $b] = array_map(fn (float $channel) => $channel <= 0.04045 ? $channel / 12.92 : (($channel + 0.055) / 1.055) ** 2.4, $srgb);

        return 0.2126 * $r + 0.7152 * $g + 0.0722 * $b;
    };
    $values = [$luminance($first), $luminance($second)];

    return (max($values) + 0.05) / (min($values) + 0.05);
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
        ['border of the fields (border-input)', accSrgb($t['input']), $background, 3.0],
        ['border of a field with the focus (border-ring)', accSrgb($ring), $background, 3.0],
        ['sidebar focus ring (ring-sidebar-ring)', accSrgb($t['sidebar-ring']), accSrgb($t['sidebar']), 3.0],
    ];

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

    return $pairs;
}

test('every colour pair reaches its WCAG threshold, in the light and in the dark theme', function (string $theme) {
    foreach (accPairs($theme) as [$what, $foreground, $background, $threshold]) {
        $ratio = accRatio($foreground, $background);

        expect($ratio)->toBeGreaterThanOrEqual($threshold, sprintf('%s, %s theme: %.2f:1 (needed %.1f:1)', $what, $theme, $ratio, $threshold));
    }
})->with(['light', 'dark']);

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
        ->and(accRatio(accSrgb(accTailwind('green-600')), accSrgb($white)))->toBeLessThan(4.5);
});

test('the borders of cards and separators keep their light colour', function () {
    // Decorative: no threshold. Only the fields (--input) became darker.
    expect(accTokens('light')['border'])->toBe([0.922, 0.0, 0.0])
        ->and(accTokens('dark')['border'])->toBe([0.269, 0.0, 0.0]);
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
