<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

// The photos of the home carousel (issue #144, step 6, docs/photos.md): the files, the script that
// makes them, the record of their sources, the pure functions and the source of the slide.

const HPH_DIR = 'resources/images/home';
const HPH_WIDTHS = [640, 1280, 1920];
const HPH_LIMITS = [640 => 60 * 1024, 1280 => 150 * 1024, 1920 => 300 * 1024];

/**
 * The photos listed in resources/js/lib/home-photos.ts: slug => [widths].
 *
 * @return array<string, list<int>>
 */
function hphListed(): array
{
    preg_match_all("/import \\w+ from '\\.\\.\\/\\.\\.\\/images\\/home\\/([a-z-]+?)-(\\d+)\\.webp';/", (string) file_get_contents(resource_path('js/lib/home-photos.ts')), $imports, PREG_SET_ORDER);
    $photos = [];
    foreach ($imports as [, $slug, $width]) {
        $photos[$slug][] = (int) $width;
    }
    foreach ($photos as &$widths) {
        sort($widths);
    }

    return $photos;
}

/**
 * The chunks of a WebP file (RIFF): their four-letter names, in order.
 *
 * @return list<string>
 */
function hphChunks(string $bytes): array
{
    $chunks = [];
    $offset = 12;
    while ($offset + 8 <= strlen($bytes)) {
        $name = substr($bytes, $offset, 4);
        $size = unpack('V', substr($bytes, $offset + 4, 4))[1];
        $chunks[] = $name;
        $offset += 8 + $size + ($size % 2);
    }

    return $chunks;
}

/**
 * The rows of a table of docs/photos.md, under the given heading: lists of cells.
 *
 * @return list<list<string>>
 */
function hphTable(string $heading): array
{
    $doc = (string) file_get_contents(base_path('docs/photos.md'));
    preg_match('/^## '.preg_quote($heading, '/').'\n([\s\S]*?)(?=^## |\z)/m', $doc, $section);
    $rows = [];
    foreach (explode("\n", $section[1] ?? '') as $line) {
        if (str_starts_with($line, '| ') && ! str_starts_with($line, '| Slug') && ! str_starts_with($line, '| ---')) {
            $rows[] = array_map('trim', explode('|', trim($line, ' |')));
        }
    }

    return $rows;
}

// ---------------------------------------------------------------------------------------------
// The files
// ---------------------------------------------------------------------------------------------

test('every size of every photo exists, is a real WebP of the right size, under its weight, without metadata', function (string $slug, int $width) {
    $path = base_path(HPH_DIR."/{$slug}-{$width}.webp");
    expect(is_file($path))->toBeTrue("Missing {$slug}-{$width}.webp");

    $bytes = (string) file_get_contents($path);
    $size = getimagesizefromstring($bytes);

    expect(substr($bytes, 0, 4).substr($bytes, 8, 4))->toBe('RIFFWEBP')
        ->and($size[2])->toBe(IMAGETYPE_WEBP)
        ->and($size[0])->toBe($width)
        // The originals are 3:2 and are not cut.
        ->and($size[1])->toBe((int) round($width * 2 / 3))
        ->and(strlen($bytes))->toBeLessThanOrEqual(HPH_LIMITS[$width], "{$slug}-{$width}: ".strlen($bytes).' bytes');
    // Only the pixels: no EXIF, no XMP, no ICC profile.
    expect(array_intersect(hphChunks($bytes), ['EXIF', 'XMP ', 'ICCP']))->toBe([]);
})->with(function () {
    foreach (['barbiere', 'nails', 'treccine'] as $slug) {
        foreach (HPH_WIDTHS as $width) {
            yield "{$slug} {$width}" => [$slug, $width];
        }
    }
});

test('the folder holds the WebP sizes and nothing else: no original, no file that is not listed', function () {
    $files = array_map(fn ($file) => $file->getFilename(), File::files(base_path(HPH_DIR)));
    sort($files);

    $expected = [];
    foreach (hphListed() as $slug => $widths) {
        foreach ($widths as $width) {
            $expected[] = "{$slug}-{$width}.webp";
        }
    }
    sort($expected);

    expect($files)->toBe($expected)
        ->and(hphListed())->toBe(['barbiere' => HPH_WIDTHS, 'nails' => HPH_WIDTHS, 'treccine' => HPH_WIDTHS]);
    // No original anywhere among the images of the site.
    foreach (File::allFiles(resource_path('images')) as $file) {
        expect(strtolower($file->getExtension()))->not->toBeIn(['jpg', 'jpeg', 'png', 'heic', 'gif', 'tif', 'tiff'], $file->getPathname());
    }
});

test('the size written in home-photos.ts is the size of the largest file', function () {
    $source = (string) file_get_contents(resource_path('js/lib/home-photos.ts'));

    foreach (hphListed() as $slug => $widths) {
        $largest = getimagesize(base_path(HPH_DIR."/{$slug}-".max($widths).'.webp'));
        expect($source)->toMatch("/{$slug}: \\{\\s*width: {$largest[0]},\\s*height: {$largest[1]},/");
    }
});

// ---------------------------------------------------------------------------------------------
// The script and the zones made unreadable
// ---------------------------------------------------------------------------------------------

test('the script declares its sizes, limits and the zones made unreadable for barbiere and nails', function () {
    $script = (string) file_get_contents(base_path('scripts/home-photos.php'));

    expect($script)
        ->toContain('const WIDTHS = [640, 1280, 1920];')
        ->toContain('const LIMITS = [640 => 60 * 1024, 1280 => 150 * 1024, 1920 => 300 * 1024];')
        ->toContain('const QUALITY = 80;')
        ->toContain('const MIN_QUALITY = 70;')
        ->toContain('const BLOCK = 48;')
        ->toContain('pixelate($source, $x1, $y1, $x2, $y2);')
        // Only GD: nothing downloaded.
        ->not->toMatch('/curl|file_get_contents\(\s*[\'"]https?:|wget/');

    foreach (['barbiere' => 1, 'nails' => 2] as $slug => $count) {
        expect(preg_match("/'{$slug}' => \\[\\s*((?:\\/\\/[^\\n]*\\s*)?(?:\\[\\d+, \\d+, \\d+, \\d+\\],\\s*)+)\\]/", $script, $match))->toBe(1, "No zones for {$slug}");
        preg_match_all('/\[(\d+), (\d+), (\d+), (\d+)\]/', $match[1], $zones, PREG_SET_ORDER);
        expect($zones)->toHaveCount($count);
        foreach ($zones as [$zone, $x1, $y1, $x2, $y2]) {
            expect((int) $x2)->toBeGreaterThan((int) $x1)->and((int) $y2)->toBeGreaterThan((int) $y1);
            // The same zones are written in the documentation.
            expect((string) file_get_contents(base_path('docs/photos.md')))->toContain("`{$zone}`");
        }
    }
});

test('the zones are pixelated in the largest size: every block is one flat colour', function (string $slug, int $originalWidth, int $count) {
    $script = (string) file_get_contents(base_path('scripts/home-photos.php'));
    preg_match("/'{$slug}' => \\[([\\s\\S]*?)\\n    \\],/", $script, $match);
    preg_match_all('/\[(\d+), (\d+), (\d+), (\d+)\]/', $match[1] ?? '', $zones, PREG_SET_ORDER);
    // Never an empty check: the zones of today are there.
    expect($zones)->toHaveCount($count);
    $image = imagecreatefromwebp(base_path(HPH_DIR."/{$slug}-1920.webp"));
    $scale = 1920 / $originalWidth;
    $block = 48 * $scale;

    foreach ($zones as [, $x1, $y1, $x2, $y2]) {
        // The middle third of every whole block: far from the edges, which the resize blurs.
        for ($by = (int) $y1; $by + 48 <= (int) $y2; $by += 48) {
            for ($bx = (int) $x1; $bx + 48 <= (int) $x2; $bx += 48) {
                $left = (int) ceil($bx * $scale + $block / 3);
                $top = (int) ceil($by * $scale + $block / 3);
                $channels = [];
                for ($y = $top; $y < $top + $block / 3 - 1; $y++) {
                    for ($x = $left; $x < $left + $block / 3 - 1; $x++) {
                        $rgb = imagecolorat($image, $x, $y);
                        $channels[] = [($rgb >> 16) & 255, ($rgb >> 8) & 255, $rgb & 255];
                    }
                }
                $spread = max(array_map(fn (int $c) => max(array_column($channels, $c)) - min(array_column($channels, $c)), [0, 1, 2]));
                expect($spread)->toBeLessThan(24, "{$slug}: the block at {$bx},{$by} is not flat ({$spread})");
            }
        }
    }
})->with([
    'barbiere' => ['barbiere', 5472, 1],
    'nails' => ['nails', 6720, 2],
]);

// ---------------------------------------------------------------------------------------------
// docs/photos.md
// ---------------------------------------------------------------------------------------------

test('docs/photos.md has a complete row for every photo of the site, and none for a photo that is not there', function () {
    $sources = hphTable('Fonti');
    $controls = hphTable('Controlli e file');

    expect(array_column($sources, 0))->toBe(array_keys(hphListed()))
        ->and(array_column($controls, 0))->toBe(array_keys(hphListed()));

    foreach ($sources as [$slug, $site, $author, $page, $licence, $date]) {
        expect($site)->toBeIn(['Unsplash', 'Pexels', 'Pixabay'], $slug)
            ->and($author)->not->toBeEmpty()
            ->and($page)->toMatch('#^https://(www\.)?(unsplash\.com|pexels\.com|pixabay\.com)/\S+$#', $slug)
            ->and($licence)->not->toBeEmpty()
            ->and($date)->toMatch('/^\d{4}-\d{2}-\d{2}$/');
    }
    foreach ($controls as $row) {
        expect($row)->toHaveCount(6)
            ->and(array_filter($row, fn (string $cell) => $cell === ''))->toBe([], $row[0]);
    }

    expect((string) file_get_contents(base_path('docs/photos.md')))
        ->toContain('Prima del lancio ricontrollare la licenza sulla pagina ufficiale di ogni foto.')
        ->toContain('Per Pexels verificare anche se il credito')
        ->toContain('verificato a occhio da Franks il 2026-11-07, l\'età non è indicata nella pagina di Unsplash');
});

// ---------------------------------------------------------------------------------------------
// The pure functions, run with Node
// ---------------------------------------------------------------------------------------------

test('a category with a photo gets its sizes, smallest first; one without, or a new one, null and no slide', function () {
    $script = sys_get_temp_dir().'/griot-photos-'.bin2hex(random_bytes(6)).'.mts';
    $helper = resource_path('js/lib/home-carousel.ts');
    file_put_contents($script, "import * as h from '{$helper}';
const photos = { treccine: { width: 1920, height: 1280, variants: [{ width: 1920, url: '/a-1920.webp' }, { width: 640, url: '/a-640.webp' }, { width: 1280, url: '/a-1280.webp' }] }, vuota: { width: 1, height: 1, variants: [] }, piccola: { width: 900, height: 600, variants: [{ width: 640, url: '/b-640.webp' }] } };
const t = (key) => key;
console.log(JSON.stringify({
    treccine: h.slideImage('treccine', photos),
    small: h.slideImage('piccola', photos),
    without: h.slideImage('barbiere', photos),
    empty: h.slideImage('vuota', photos),
    prototype: h.slideImage('constructor', photos),
    slides: h.buildSlides(t, [{ id: 1, slug: 'treccine', name: 'T' }, { id: 2, slug: 'nuova', name: 'N' }], photos).map((s) => s.id),
    noPhotos: h.buildSlides(t, [{ id: 1, slug: 'treccine', name: 'T' }]),
}));\n");

    try {
        $result = Process::run(['node', '--experimental-strip-types', '--no-warnings', $script]);
    } finally {
        unlink($script);
    }

    expect($result->successful())->toBeTrue($result->errorOutput());
    expect(json_decode($result->output(), true, flags: JSON_THROW_ON_ERROR))->toBe([
        'treccine' => ['src' => '/a-1280.webp', 'srcSet' => '/a-640.webp 640w, /a-1280.webp 1280w, /a-1920.webp 1920w', 'width' => 1920, 'height' => 1280],
        'small' => ['src' => '/b-640.webp', 'srcSet' => '/b-640.webp 640w', 'width' => 900, 'height' => 600],
        'without' => null,
        'empty' => null,
        'prototype' => null,
        // The category without a photo has no slide.
        'slides' => [1],
        'noPhotos' => [],
    ]);
});

// ---------------------------------------------------------------------------------------------
// The slide
// ---------------------------------------------------------------------------------------------

test('the photo: decorative, its real size, srcset and sizes, at once on the first slide only', function () {
    $source = (string) file_get_contents(resource_path('js/components/home/home-carousel.tsx'));
    preg_match_all('/<img[\s\S]*?\/>/', $source, $images);

    expect($images[0])->toHaveCount(1);
    expect($images[0][0])
        ->toContain('src={slide.image.src}')
        ->toContain('srcSet={slide.image.srcSet}')
        ->toContain('sizes={PHOTO_SIZES}')
        ->toContain('width={slide.image.width}')
        ->toContain('height={slide.image.height}')
        ->toContain('alt=""')
        ->toContain('decoding="async"')
        ->toMatch("/\\{\\.\\.\\.\\(position === 0\\s*\\?\\s*\\{\\s*loading: 'eager',\\s*fetchPriority: 'high',?\\s*\\}\\s*:\\s*\\{ loading: 'lazy' \\}\\)\\}/")
        ->toContain('object-cover');
    expect(substr_count($source, 'fetchPriority'))->toBe(1)
        ->and(substr_count($source, "loading: 'eager'"))->toBe(1);
    // The width of the right column of the band: all the screen on phones, half from 768 px, at
    // most 540 px (half of the 72rem container, less the gaps).
    expect($source)->toContain("const PHOTO_SIZES = '(min-width: 1152px) 540px, (min-width: 768px) 50vw, 100vw';");
    expect((string) file_get_contents(resource_path('js/pages/home.tsx')))->toContain("import { HOME_PHOTOS } from '@/lib/home-photos';")
        ->toContain('buildSlides(t, categories, HOME_PHOTOS)')
        // The same photos in the row of the categories (#144 step 8, HomeCategoriesRowTest).
        ->toContain('categoryCards(categories, HOME_PHOTOS)');
});

test('the photo of a slide is on the page only from the slide\'s first visit, then it stays', function () {
    expect((string) file_get_contents(resource_path('js/components/home/home-carousel.tsx')))
        ->toContain('const [seen, setSeen] = useState<number[]>([0]);')
        ->toMatch('/const go = \(target: number\) => \{\s*setChosen\(target\);\s*setSeen\(\(shown\) =>\s*shown\.includes\(target\) \? shown : \[\.\.\.shown, target\],?\s*\);/')
        ->toMatch('/\{seen\.includes\(position\) && \(\s*<img/')
        // Never taken away: nothing removes a slide from "seen".
        ->not->toMatch('/setSeen\(\s*\[\s*\]|\.filter\(/');
});

test('the text is never on the photo: the photo first, the text under it on the band, the same height for all', function () {
    $source = (string) file_get_contents(resource_path('js/components/home/home-carousel.tsx'));

    expect($source)
        // Since #144 step 9 the photos are one stack and the texts another, under it: the box of
        // a photo holds the photo only.
        ->toMatch("/'relative col-start-1 row-start-1 aspect-\\[3\\/2\\] bg-muted',\\s*FADE,\\s*position !== current && HIDDEN,\\s*\\)\\}\\s*>\\s*\\{seen\\.includes\\(position\\) && \\(\\s*<img[^>]*?\\/>\\s*\\)\\}\\s*<\\/div>\\s*\\)\\)\\}\\s*<\\/div>[\\s\\S]*<div className=\"grid min-w-0 flex-1 basis-64\">[\\s\\S]*<p\\s+id=\\{slideNameId\\(slide\\.id\\)\\}/")
        ->not->toMatch('/\b(absolute inset-0)[^"]*"[^>]*>\s*<p/')
        ->not->toMatch('/\b(setInterval|setTimeout|requestAnimationFrame|sticky)\b/');
    // Since #144 step 10 the slides cross-fade, only under motion-safe: (HomeMotionTest).
    expect(withoutMotionSafe($source))->not->toMatch('/\b(transition|animate-|duration-)/');
});
