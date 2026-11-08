<?php

use Illuminate\Support\Facades\Process;

// The files of the provisional logo (docs/brand.md): what scripts/brand-assets.php writes, how the
// page uses them (name, themes, forced colours) and the script itself, run on a small image made
// here, never on the originals, which stay outside the repository.

function baFile(string $path): string
{
    return (string) file_get_contents(base_path($path));
}

/**
 * Width and height of an image file.
 *
 * @return array{0: int, 1: int}
 */
function baSize(string $path): array
{
    $size = getimagesize($path);
    expect($size)->not->toBeFalse($path);

    return [(int) $size[0], (int) $size[1]];
}

/**
 * The colour and the alpha (0 opaque, 127 transparent) of a pixel.
 *
 * @return array{0: int, 1: int, 2: int, 3: int}
 */
function baPixel(GdImage $image, int $x, int $y): array
{
    $pixel = imagecolorat($image, $x, $y);

    return [($pixel >> 16) & 0xFF, ($pixel >> 8) & 0xFF, $pixel & 0xFF, ($pixel >> 24) & 0x7F];
}

/**
 * The sRGB hex of a token of app.css, light theme.
 */
function baToken(string $name, string $theme = 'light'): string
{
    return sprintf('#%02X%02X%02X', ...array_map(fn (float $channel) => (int) round($channel * 255), accSrgb(accTokens($theme)[$name])));
}

// The files and their sizes, as lib/brand.ts declares them (the 1x one) and the script makes them.
const BA_IMAGES = [
    'resources/images/brand/wordmark-light-1x.webp' => [150, 36],
    'resources/images/brand/wordmark-light-2x.webp' => [299, 72],
    'resources/images/brand/wordmark-light-3x.webp' => [449, 108],
    'resources/images/brand/wordmark-dark-1x.webp' => [150, 36],
    'resources/images/brand/wordmark-dark-2x.webp' => [299, 72],
    'resources/images/brand/wordmark-dark-3x.webp' => [449, 108],
    'resources/images/brand/pin-1x.webp' => [22, 24],
    'resources/images/brand/pin-2x.webp' => [44, 48],
    'resources/images/brand/pin-3x.webp' => [66, 72],
    'public/brand/pin.png' => [66, 72],
    'public/brand/icon-16.png' => [16, 16],
    'public/brand/icon-32.png' => [32, 32],
    'public/brand/icon-48.png' => [48, 48],
    'public/brand/icon-180.png' => [180, 180],
    'public/brand/icon-512.png' => [512, 512],
    'public/apple-touch-icon.png' => [180, 180],
    'public/og-default.png' => [1200, 630],
];

// The heaviest each file may be, in KB (docs/brand.md).
const BA_LIMITS = [
    'wordmark' => 30, 'pin-' => 10, 'pin.png' => 10, 'icon-16' => 10, 'icon-32' => 10, 'icon-48' => 10,
    'icon-180' => 30, 'apple-touch' => 30, 'icon-512' => 120, 'favicon.ico' => 10, 'og-default' => 200,
];

// ---------------------------------------------------------------------------------------------
// The files
// ---------------------------------------------------------------------------------------------

test('every file of the logo is there with its size, and lib/brand.ts declares the 1x ones', function () {
    foreach (BA_IMAGES as $path => $size) {
        expect(baSize(base_path($path)))->toBe($size, $path);
    }

    // Nothing else in the folder of the logo.
    $files = array_map('basename', glob(resource_path('images/brand/*')) ?: []);
    sort($files);
    $expected = array_map('basename', array_filter(array_keys(BA_IMAGES), fn (string $path) => str_starts_with($path, 'resources/images/brand/')));
    sort($expected);
    expect($files)->toBe($expected);

    $brand = baFile('resources/js/lib/brand.ts');
    expect($brand)
        ->toMatch('/light: densities\(150, 36, \[\s*wordmarkLight1x,\s*wordmarkLight2x,\s*wordmarkLight3x,?\s*\]\),/')
        ->toMatch('/dark: densities\(150, 36, \[\s*wordmarkDark1x,\s*wordmarkDark2x,\s*wordmarkDark3x,?\s*\]\),/')
        ->toMatch('/export const PIN = densities\(22, 24, \[\s*pin1x,\s*pin2x,\s*pin3x,?\s*\]\);/')
        ->toContain('srcSet: `${one} 1x, ${two} 2x, ${three} 3x`,');
    preg_match_all("/import \\w+ from '\\.\\.\\/\\.\\.\\/images\\/brand\\/([a-z0-9-]+\\.webp)';/", $brand, $imports);
    $imported = $imports[1];
    sort($imported);
    expect($imported)->toBe($expected);
});

test('every file is light: under its limit', function () {
    $paths = [...array_keys(BA_IMAGES), 'public/favicon.ico'];

    foreach ($paths as $path) {
        $limit = null;
        foreach (BA_LIMITS as $part => $kb) {
            if (str_contains($path, $part)) {
                $limit = $kb;
                break;
            }
        }

        expect($limit)->not->toBeNull($path)
            ->and(filesize(base_path($path)))->toBeLessThanOrEqual($limit * 1024, $path);
    }
});

test('the favicon holds 16, 32 and 48 px, the apple-touch icon is 180 px, both linked by every page', function () {
    $ico = baFile('public/favicon.ico');
    expect(unpack('vreserved/vtype/vcount', substr($ico, 0, 6)))->toBe(['reserved' => 0, 'type' => 1, 'count' => 3]);
    $sizes = [];
    for ($i = 0; $i < 3; $i++) {
        $entry = unpack('Cwidth/Cheight/x2/vplanes/vbits/Vbytes/Voffset', substr($ico, 6 + 16 * $i, 16));
        $image = imagecreatefromstring(substr($ico, (int) $entry['offset'], (int) $entry['bytes']));
        expect($image)->not->toBeFalse();
        $sizes[] = [imagesx($image), imagesy($image)];
    }
    expect($sizes)->toBe([[16, 16], [32, 32], [48, 48]])
        ->and(baSize(public_path('apple-touch-icon.png')))->toBe([180, 180])
        ->and(baSize(public_path('og-default.png')))->toBe([1200, 630]);

    foreach (['resources/views/app.blade.php', 'resources/views/errors/layout.blade.php'] as $page) {
        expect(baFile($page))
            ->toContain('<link rel="icon" href="/favicon.ico" sizes="any">')
            ->toContain('<link rel="apple-touch-icon" href="/apple-touch-icon.png">');
    }
});

test('the originals never enter the repository: no big PNG, no file with their names', function () {
    $found = [];

    foreach (['resources', 'public', 'scripts', 'docs', 'tests', 'storage/app'] as $folder) {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(base_path($folder), FilesystemIterator::SKIP_DOTS));

        foreach ($iterator as $file) {
            $name = $file->getFilename();
            if (str_starts_with($name, 'Logo GRIOT') || str_starts_with($name, 'Présentazione') || (str_ends_with(strtolower($name), '.png') && $file->getSize() > 300 * 1024)) {
                $found[] = $file->getPathname();
            }
        }
    }

    expect($found)->toBe([]);
});

// ---------------------------------------------------------------------------------------------
// The page
// ---------------------------------------------------------------------------------------------

test('the logo is named by the name of the app, the same source as the text written instead of it', function () {
    $logo = baFile('resources/js/components/app-logo.tsx');

    expect($logo)
        ->toContain('const { name } = usePage().props;')
        ->and(substr_count($logo, 'alt={name}'))->toBe(2)
        ->and($logo)->toMatch('/forced-colors:inline[^"]*">\s*\{name\}\s*<\/span>|">\s*\{name\}\s*<\/span>/')
        // Never the name written by hand.
        ->not->toMatch('/alt="[^"]+"|>\s*Griot\s*</');
    expect(baFile('resources/js/components/app-logo-icon.tsx'))->toContain('alt=""')->toContain('aria-hidden="true"');
});

test('one image per theme: dark:hidden and hidden dark:block, never <picture> with prefers-color-scheme', function () {
    $logo = baFile('resources/js/components/app-logo.tsx');

    expect($logo)
        ->toMatch('/src=\{WORDMARK\.light\.src\}[\s\S]*?className=\{`\$\{IMAGE\} dark:hidden`\}/')
        ->toMatch('/src=\{WORDMARK\.dark\.src\}[\s\S]*?className=\{`hidden dark:not-forced-colors:block \$\{IMAGE\}`\}/');

    $sources = '';
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(resource_path('js'), FilesystemIterator::SKIP_DOTS)) as $file) {
        if (preg_match('/\.tsx?$/', $file->getPathname()) === 1) {
            $sources .= (string) file_get_contents($file->getPathname());
        }
    }
    // (The appearance switch reads the system's theme with matchMedia: that is not an image.)
    expect($sources)->not->toContain('<picture')->not->toMatch('/media=["{\'`][^"\'`]*prefers-color-scheme/');
});

test('in forced colours the images give way to the name as text, and the square of the mark keeps a border', function () {
    $logo = baFile('resources/js/components/app-logo.tsx');

    expect($logo)
        ->toContain("const IMAGE = 'h-7 w-auto md:h-9 forced-colors:hidden';")
        ->toMatch('/<span className="hidden [^"]*\btext-primary\b[^"]*\bforced-colors:inline\b[^"]*">\s*\{name\}/')
        // The collapsed sidebar: the images go, the name stays for screen readers.
        ->toContain('<span className="contents group-data-[collapsible=icon]:hidden">')
        ->toMatch('/group-data-\[collapsible=icon\]:sr-only/');
    expect(baFile('resources/js/components/app-logo-icon.tsx'))->toContain('forced-colors:border');
});

test('every image of the logo has its width and height: the page keeps its room while it loads', function () {
    $sources = baFile('resources/js/components/app-logo.tsx').baFile('resources/js/components/app-logo-icon.tsx');
    preg_match_all('/<img[\s\S]*?\/>/', $sources, $images);

    expect($images[0])->toHaveCount(3);
    foreach ($images[0] as $image) {
        expect($image)->toMatch('/width=\{[A-Z.a-z]+\.width\}/')->toMatch('/height=\{[A-Z.a-z]+\.height\}/');
    }
    expect(baFile('resources/views/errors/layout.blade.php'))->toContain('<img src="/brand/pin.png" width="22" height="24" alt="">');
});

test('the header never leaves a 320 px screen: the language and "log in" go one under the other', function () {
    expect(baFile('resources/js/layouts/public-layout.tsx'))
        ->toContain('<div className="flex flex-wrap items-center justify-end gap-x-4 gap-y-1">');
});

// ---------------------------------------------------------------------------------------------
// Colours
// ---------------------------------------------------------------------------------------------

test('no colour written by hand in the logo; the colours of the script are the tokens of app.css', function () {
    $components = baFile('resources/js/components/app-logo.tsx').baFile('resources/js/components/app-logo-icon.tsx').baFile('resources/js/lib/brand.ts');
    expect($components)
        ->not->toMatch('/#[0-9a-fA-F]{6}\b|rgba?\(|oklch\(/')
        ->not->toMatch('/\b(?:bg|text|border|fill|stroke)-(?:red|green|orange|amber|yellow|neutral|gray|white|black)\b/');

    $script = baFile('scripts/brand-assets.php');
    $constant = function (string $name) use ($script): string {
        expect(preg_match("/const {$name} = \\[0x([0-9A-F]{2}), 0x([0-9A-F]{2}), 0x([0-9A-F]{2})\\];/", $script, $match))->toBe(1, $name);

        return "#{$match[1]}{$match[2]}{$match[3]}";
    };
    expect($constant('BROWN'))->toBe(baToken('hero'))
        ->and($constant('CREAM'))->toBe(baToken('hero-foreground'))
        ->and($constant('BACKGROUND'))->toBe(baToken('background'))
        ->and($constant('DECOR'))->toBe(baToken('decor'));
});

test('the orange of the pin stands out on the brown of its square, in both themes', function () {
    $pin = imagecreatefrompng(public_path('brand/pin.png'));
    expect($pin)->not->toBeFalse();

    // The darkest opaque orange of the pin.
    $darkest = null;
    for ($y = 0; $y < imagesy($pin); $y++) {
        for ($x = 0; $x < imagesx($pin); $x++) {
            [$r, $g, $b, $alpha] = baPixel($pin, $x, $y);
            if ($alpha === 0 && $r > $b + 100 && ($darkest === null || $r + $g < $darkest[0] + $darkest[1])) {
                $darkest = [$r, $g, $b];
            }
        }
    }
    expect($darkest)->not->toBeNull();

    foreach (['light', 'dark'] as $theme) {
        $ratio = accRatio(array_map(fn (int $channel) => $channel / 255, $darkest), accSrgb(accTokens($theme)['hero']));
        expect($ratio)->toBeGreaterThanOrEqual(3.0, sprintf('%s theme: %.2f:1', $theme, $ratio));
    }
});

// ---------------------------------------------------------------------------------------------
// The script, on a small image made here
// ---------------------------------------------------------------------------------------------

test('the script cuts the word, removes the arcs, makes the white transparent without a halo', function () {
    $folder = sys_get_temp_dir().'/griot-brand-'.bin2hex(random_bytes(6));
    mkdir($folder.'/originals', 0775, true);

    // A logo of 1536x1024 on white: a brown bar for the letters, an orange pin with a white
    // centre around PIN_SEED, an orange arc across the letters, far from the pin.
    $logo = imagecreatetruecolor(1536, 1024);
    imagefill($logo, 0, 0, (int) imagecolorallocate($logo, 255, 255, 255));
    imagefilledrectangle($logo, 150, 600, 850, 780, (int) imagecolorallocate($logo, 0x40, 0x13, 0x01));
    imagefilledellipse($logo, 120 + 860, 540 + 90, 120, 120, (int) imagecolorallocate($logo, 0xF0, 0x70, 0x10));
    imagefilledellipse($logo, 120 + 860, 540 + 90, 40, 40, (int) imagecolorallocate($logo, 255, 255, 255));
    imagefilledrectangle($logo, 150, 690, 850, 700, (int) imagecolorallocate($logo, 0xF8, 0x90, 0x00));
    imagepng($logo, $folder.'/originals/Logo GRIOT_ Talenti e Comunità.png');

    try {
        $result = Process::timeout(120)->run(['php', base_path('scripts/brand-assets.php'), $folder.'/originals', $folder.'/out']);
        expect($result->successful())->toBeTrue($result->errorOutput());

        $light = imagecreatefromwebp($folder.'/out/resources/images/brand/wordmark-light-3x.webp');
        $dark = imagecreatefromwebp($folder.'/out/resources/images/brand/wordmark-dark-3x.webp');
        expect($light)->not->toBeFalse()->and($dark)->not->toBeFalse();
        expect(imagesy($light))->toBe(108);

        // The corner: transparent. The letters: brown in the light one, cream in the dark one.
        [$width, $height] = [imagesx($light), imagesy($light)];
        // (The top corners are outside both the bar and the round pin.)
        expect(baPixel($light, 0, 0)[3])->toBe(127)
            ->and(baPixel($light, $width - 1, 0)[3])->toBe(127);
        $letter = baPixel($light, 40, (int) round($height * 0.5));
        expect($letter[3])->toBeLessThan(10)->and($letter[0])->toBeLessThan(110)->and($letter[2])->toBeLessThan(40);
        $cream = baPixel($dark, 40, (int) round($height * 0.5));
        expect($cream[0])->toBeGreaterThan(230)->and($cream[2])->toBeGreaterThan(200);

        // The arc that crossed the letters is gone: no orange on its row, away from the pin.
        $arcRow = (int) round((695 - 570) * $height / 211);
        for ($x = 0; $x < (int) round($width * 0.7); $x++) {
            [$r, $g, $b, $alpha] = baPixel($light, $x, $arcRow);
            expect($alpha < 100 && $r > 200 && $b < 80)->toBeFalse("orange left at {$x},{$arcRow}");
        }

        // No light halo: no pixel of the wordmark is light and partly transparent at once.
        $halo = 0;
        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                [$r, $g, $b, $alpha] = baPixel($light, $x, $y);
                $halo += ($alpha > 20 && $alpha < 127 && min($r, $g, $b) > 200) ? 1 : 0;
            }
        }
        expect($halo)->toBe(0);

        // The other files are there.
        foreach (['public/favicon.ico', 'public/apple-touch-icon.png', 'public/og-default.png', 'public/brand/pin.png', 'resources/images/brand/pin-1x.webp'] as $file) {
            expect(file_exists("{$folder}/out/{$file}"))->toBeTrue($file);
        }
        expect(baSize("{$folder}/out/public/og-default.png"))->toBe([1200, 630]);
    } finally {
        Process::run(['rm', '-rf', $folder]);
    }
});

test('no real contact in the files of the logo: no email address, no phone number', function () {
    $sources = baFile('scripts/brand-assets.php').baFile('resources/js/lib/brand.ts').baFile('resources/js/components/app-logo.tsx')
        .baFile('resources/js/components/app-logo-icon.tsx').baFile('docs/brand.md');

    expect($sources)
        ->not->toMatch('/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/')
        ->not->toMatch('/(\+|00)\d[\d ]{7,}/')
        ->not->toContain('mailto:')
        ->not->toContain('wa.me/');
});
