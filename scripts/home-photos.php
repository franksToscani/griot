<?php

/*
 * The photos of the home carousel (issue #144, docs/photos.md): from the originals, which stay
 * outside the repository, to the WebP sizes in resources/images/home.
 *
 *     php scripts/home-photos.php /path/to/the/originals
 *
 * The originals are named after the slug of their category (barbiere.jpg, …). For each one:
 * the zones listed in ZONES are pixelated first (a brand on a label, a sticker), in the
 * coordinates of the original; then the photo is resized to each width of WIDTHS that is not
 * larger than the original (never enlarged) and written as WebP. GD writes the pixels only: no
 * EXIF, no GPS, no comment. A size heavier than its limit is written again with a lower quality,
 * down to MIN_QUALITY; if it is still too heavy the script stops.
 *
 * Only GD, already used by the app (App\Profile\WebpEncoder): nothing is downloaded.
 */

const WIDTHS = [640, 1280, 1920];

// The heaviest each size may be, in bytes.
const LIMITS = [640 => 60 * 1024, 1280 => 150 * 1024, 1920 => 300 * 1024];

const QUALITY = 80;

const MIN_QUALITY = 70;

// The zones to make unreadable, per photo, in pixels of the original: [x1, y1, x2, y2].
const ZONES = [
    // The label with a brand on the barber's cape.
    'barbiere' => [
        [1250, 2150, 1680, 2720],
    ],
    // The sticker "CND UV LAMP" with its warning, and the logo "CND" on the UV lamp.
    'nails' => [
        [1990, 3080, 2620, 3660],
        [3500, 3880, 3950, 4480],
    ],
];

// The size of a block of the pixelation, in pixels of the original: at 1920 px wide a block is
// still about 15 px, too big to read a letter.
const BLOCK = 48;

$originals = $argv[1] ?? null;
$output = __DIR__.'/../resources/images/home';

if ($originals === null || ! is_dir($originals)) {
    fwrite(STDERR, "Usage: php scripts/home-photos.php /path/to/the/originals\n");
    exit(1);
}

if (! is_dir($output)) {
    mkdir($output, 0775, true);
}

foreach (glob(rtrim($originals, '/').'/*.{jpg,jpeg,png,webp}', GLOB_BRACE) ?: [] as $path) {
    $slug = pathinfo($path, PATHINFO_FILENAME);
    $source = imagecreatefromstring((string) file_get_contents($path));

    if ($source === false) {
        fwrite(STDERR, "{$slug}: not an image\n");
        exit(1);
    }

    foreach (ZONES[$slug] ?? [] as [$x1, $y1, $x2, $y2]) {
        pixelate($source, $x1, $y1, $x2, $y2);
    }

    $width = imagesx($source);
    $height = imagesy($source);

    foreach (WIDTHS as $side) {
        if ($side > $width) {
            continue;
        }

        $resized = imagescale($source, $side, (int) round($height * $side / $width), IMG_BICUBIC);
        $file = "{$output}/{$slug}-{$side}.webp";
        $quality = QUALITY;

        while (true) {
            imagewebp($resized, $file, $quality);
            clearstatcache(true, $file);
            $bytes = (int) filesize($file);

            if ($bytes <= LIMITS[$side] || $quality <= MIN_QUALITY) {
                break;
            }

            $quality -= 2;
        }

        if ($bytes > LIMITS[$side]) {
            fwrite(STDERR, "{$slug}-{$side}: {$bytes} bytes, over the limit even at quality {$quality}\n");
            exit(1);
        }

        printf("%s-%d.webp %dx%d quality %d, %.1f KB\n", $slug, $side, imagesx($resized), imagesy($resized), $quality, $bytes / 1024);
    }
}

/**
 * Replace a zone with big blocks of one colour each: nothing written in it can be read any more.
 */
function pixelate(GdImage $image, int $x1, int $y1, int $x2, int $y2): void
{
    $x2 = min($x2, imagesx($image));
    $y2 = min($y2, imagesy($image));

    for ($y = $y1; $y < $y2; $y += BLOCK) {
        for ($x = $x1; $x < $x2; $x += BLOCK) {
            $w = min(BLOCK, $x2 - $x);
            $h = min(BLOCK, $y2 - $y);
            // The average colour of the block: the block shrunk to one pixel.
            $pixel = imagecreatetruecolor(1, 1);
            imagecopyresampled($pixel, $image, 0, 0, $x, $y, 1, 1, $w, $h);
            imagefilledrectangle($image, $x, $y, $x + $w - 1, $y + $h - 1, imagecolorat($pixel, 0, 0));
        }
    }
}
