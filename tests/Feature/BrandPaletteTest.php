<?php

// The warm palette of the provisional logo (docs/accessibility.md, "Palette"): every colour comes
// from the tokens of resources/css/app.css, except a few known places that cannot read them; the
// orange of the brand and the decor line never say "error"; the links in a text are underlined,
// since they are brown as the text around them. The contrasts are in AccessibilityContrastTest.

function bpSource(string $path): string
{
    return (string) file_get_contents(base_path($path));
}

/**
 * The React sources of the application, the generated folders left out.
 *
 * @return array<string, string> path relative to resources/js => source
 */
function bpReactSources(): array
{
    $files = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(resource_path('js'), FilesystemIterator::SKIP_DOTS));

    foreach ($iterator as $file) {
        $path = $file->getPathname();
        $relative = str_replace(resource_path('js').'/', '', $path);

        if (preg_match('/\.tsx?$/', $path) === 1 && preg_match('#^(routes|actions|wayfinder)/#', $relative) !== 1) {
            $files[$relative] = (string) file_get_contents($path);
        }
    }

    ksort($files);

    return $files;
}

/**
 * The sRGB hex of a token of app.css, as the browser draws it.
 */
function bpHex(string $theme, string $token): string
{
    return sprintf('#%02X%02X%02X', ...array_map(fn (float $channel) => (int) round($channel * 255), accSrgb(accTokens($theme)[$token])));
}

test('no colour written by hand in React, but the loading bar, which repeats the brown of the buttons', function () {
    $written = [];

    foreach (bpReactSources() as $path => $source) {
        // A colour in a string: "#54220F", 'rgb(…)', 'oklch(…)'. Never the numbers of issues in
        // the comments ("#144").
        if (preg_match_all('/[\'"`](#[0-9a-fA-F]{3,8}|(?:rgba?|hsla?|oklch)\([^)]*\))[\'"`]/', $source, $found) > 0) {
            $written[$path] = $found[1];
        }
    }

    expect($written)->toBe(['app.tsx' => ['#54220F']])
        // The same brown as --primary of the light theme.
        ->and(bpHex('light', 'primary'))->toBe('#54220F');
    expect(bpSource('resources/js/app.tsx'))->toMatch("/progress: \\{\\s*(\\/\\/[^\\n]*\\s*)*color: '#54220F',\\s*\\}/");
});

test('the colours of Tailwind\'s palette only where they are known: errors, confirmations, the kit', function () {
    $users = [];

    foreach (bpReactSources() as $path => $source) {
        if (preg_match('/\b(?:text|bg|border|ring|fill|stroke|from|to|via|outline|decoration)-(?:red|green|blue|yellow|amber|orange|emerald|teal|sky|cyan|lime|rose|pink|purple|violet|indigo|gray|slate|zinc|neutral|stone)-\d{2,3}\b/', $source) === 1) {
            $users[] = $path;
        }
    }

    // Errors and the delete-account warning in red; the status messages in green; neutral greys
    // in the user's initials and in the sidebar's footer links, left from the starter kit (#115).
    expect($users)->toBe([
        'components/delete-user.tsx',
        'components/input-error.tsx',
        'components/nav-footer.tsx',
        'components/user-info.tsx',
        'pages/auth/forgot-password.tsx',
        'pages/auth/login.tsx',
        'pages/auth/verify-email.tsx',
        'pages/settings/profile.tsx',
    ]);
});

test('the pages outside React use the tokens too: the background before the page, the error pages, neutral emails', function () {
    $app = bpSource('resources/views/app.blade.php');
    preg_match('/html \{\s*background-color: oklch\(([^)]*)\);/', $app, $light);
    preg_match('/html\.dark \{\s*background-color: oklch\(([^)]*)\);/', $app, $dark);

    expect(array_map('floatval', preg_split('/\s+/', $light[1] ?? '') ?: []))->toBe(accTokens('light')['background'])
        ->and(array_map('floatval', preg_split('/\s+/', $dark[1] ?? '') ?: []))->toBe(accTokens('dark')['background']);

    // The emails stay neutral: black text on white, nothing of the palette.
    foreach (glob(resource_path('views/mail/*.blade.php')) ?: [] as $mail) {
        preg_match_all('/#[0-9a-fA-F]{6}\b/', (string) file_get_contents($mail), $found);
        expect(array_values(array_diff(array_unique($found[0]), ['#ffffff', '#18181b'])))->toBe([], basename($mail));
    }
});

test('the orange of the brand and the decor line never say "error", and errors never use them', function () {
    foreach (bpReactSources() as $path => $source) {
        $orange = preg_match('/\b(?:bg|text|border|ring|fill|stroke)-(?:brand|decor)\b/', $source) === 1;
        $error = preg_match('/destructive|InputError|aria-invalid|role="alert"|-red-\d/', $source) === 1;

        expect($orange && $error)->toBeFalse("{$path} mixes the orange of the brand with an error");
    }

    // The decor is only the line under the public header (VisualIdentityTest), the brand nowhere
    // near a message.
    foreach (['components/input-error.tsx', 'components/ui/alert.tsx', 'components/delete-user.tsx', 'components/ui/button.tsx'] as $path) {
        expect(bpSource("resources/js/{$path}"))->not->toMatch('/\b(?:brand|decor|highlight)\b/');
    }

    // And the colours stay apart: the orange and the red of the errors at least 20° of hue away.
    foreach (['light', 'dark'] as $theme) {
        $t = accTokens($theme);
        expect(abs($t['brand'][2] - $t['destructive'][2]))->toBeGreaterThanOrEqual(20.0, $theme)
            ->and(abs($t['decor'][2] - $t['destructive'][2]))->toBeGreaterThanOrEqual(20.0, $theme);
    }
});

test('a link in a text is underlined: brown as the text around it, the line tells it apart', function () {
    // The class "underline" itself, not "underline-offset-4" alone.
    expect(bpSource('resources/js/components/text-link.tsx'))->toMatch("/'(?:[^']*\\s)?underline(?![\\w-])[^']*'/");
    expect(bpSource('resources/js/components/privacy-link.tsx'))->toMatch('/className="(?:[^"]*\s)?underline(?![\w-])/');
    expect(substr_count(bpSource('resources/js/layouts/public-layout.tsx'), "'underline underline-offset-4'"))->toBeGreaterThanOrEqual(3);

    foreach (bpReactSources() as $path => $source) {
        expect($source)->not->toMatch('/\bno-underline\b/', $path)
            // A link coloured as the buttons would be told apart by its colour only.
            ->not->toMatch('/<(?:a|Link|TextLink)\b[^>]*className="[^"]*\btext-primary\b/', $path);
    }

    // Brown as the text: the colour alone would not be enough (WCAG 1.4.1), so the line stays.
    $light = accTokens('light');
    expect(accRatio(accSrgb($light['primary']), accSrgb($light['foreground'])))->toBeLessThan(3.0);
});
