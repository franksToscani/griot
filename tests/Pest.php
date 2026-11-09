<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Assert;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}

/*
|--------------------------------------------------------------------------
| Colour contrast
|--------------------------------------------------------------------------
|
| The colours as the browser shows them, read where they are written: the tokens of
| resources/css/app.css and Tailwind's palette. Used by AccessibilityContrastTest and by
| ErrorPagesTest, so that both measure with the same formulas.
|
*/

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
 * The "--name: oklch(L C H)" declarations of a CSS text; L may be written as a percentage, and the
 * hue of a grey as "none" (no hue: 0, as CSS reads it).
 *
 * @return array<string, array{0: float, 1: float, 2: float}>
 */
function accParse(string $css): array
{
    preg_match_all('/--([a-z0-9-]+):\s*oklch\(([\d.]+)(%?)\s+([\d.]+)\s+([\d.]+|none)\)/', $css, $matches, PREG_SET_ORDER);
    $colors = [];

    foreach ($matches as [, $name, $lightness, $percent, $chroma, $hue]) {
        $colors[$name] = [(float) $lightness / ($percent === '%' ? 100 : 1), (float) $chroma, (float) $hue];
    }

    return $colors;
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
 * A source without its motion-safe: classes (docs/accessibility.md, "Movimento"): what is left is
 * what exists with "reduce motion" too, where nothing may move. The tests that forbid transition,
 * duration-, animate- or translate- read this, so those stay forbidden outside motion-safe:.
 */
function withoutMotionSafe(string $source): string
{
    return (string) preg_replace('/(?<![\w:-])motion-safe:(?:\[[^\]]*\]|[^\s\'"`\[])+/', '', $source);
}

/*
|--------------------------------------------------------------------------
| PostgreSQL
|--------------------------------------------------------------------------
|
| The same suite runs on SQLite (here, phpunit.xml) and on PostgreSQL (the tests-postgres job,
| docs/ci.md).
|
*/

/**
 * Skip a test that simulates two requests at the same moment with an insert on the same
 * connection, inside the transaction of the test (RefreshDatabase). On PostgreSQL the failed
 * statement that follows spoils that whole transaction, so the code under test cannot read again
 * as it would after a real race: there the test is skipped, with its reason; SQLite runs it.
 */
function skipOnPostgresSameTransactionRace(): void
{
    if (DB::connection()->getDriverName() === 'pgsql') {
        Assert::markTestSkipped('PostgreSQL: the race is simulated inside the transaction of the test, which a failed statement spoils (docs/ci.md).');
    }
}
