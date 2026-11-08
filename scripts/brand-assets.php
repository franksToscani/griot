<?php

/*
 * The provisional logo of Griot (docs/brand.md): from the original PNG, which stays outside the
 * repository, to every file the site uses.
 *
 *     php scripts/brand-assets.php /path/to/the/originals [/path/to/the/repository]
 *
 * Only the logo is read (LOGO, a white background, 1536x1024): the word GRIOT with its pin is cut
 * out of it, without the figure, the motto and the orange arcs that cross the letters. The second
 * original (the board of ideas) is not needed. Without the second argument the files are written
 * in this repository:
 *
 *   resources/images/brand/wordmark-{light,dark}-{1,2,3}x.webp   the header, 36/72/108 px high
 *   resources/images/brand/pin-{1,2,3}x.webp                      the small mark, 24/48/72 px high
 *   public/brand/pin.png                                          the mark of the error pages
 *   public/brand/icon-{16,32,48,180,512}.png                      the square icon
 *   public/favicon.ico (16, 32, 48), public/apple-touch-icon.png (180, square, opaque)
 *   public/og-default.png                                         the link previews, 1200x630
 *
 * The white is turned into transparency once, at full size ("colour to alpha": each pixel loses
 * the white it contains, so no halo on a dark background); the pin, found as the orange region
 * around a point inside it, stays opaque with its white centre. Then every size is a resize of
 * that image (GD weighs the colours by their alpha). The colours are those of
 * resources/css/app.css (BrandAssetsTest compares them). Only GD, already used by the app.
 */

const LOGO = 'Logo GRIOT_ Talenti e Comunità.png';

// The word GRIOT with its pin in the original: [x, y, width, height].
const WORD = [120, 540, 1300, 305];

// A point inside the orange of the pin, in WORD: the pin is the orange region around it, with
// what it encloses (its white centre and its dot). Every other orange or yellow pixel is a piece
// of an arc and becomes white; the pin's brown ring is kept anyway.
const PIN_SEED = [860, 60];

const WORDMARK_HEIGHTS = [1 => 36, 2 => 72, 3 => 108];

const PIN_HEIGHTS = [1 => 24, 2 => 48, 3 => 72];

const ICON_SIZES = [16, 32, 48, 180, 512];

// The tokens of app.css, light theme: --hero (the brown of the icon), --hero-foreground (the cream
// of the dark wordmark), --background (the cream of the preview) and --decor (its orange line).
const BROWN = [0x3C, 0x13, 0x04];

const CREAM = [0xFE, 0xFA, 0xF1];

const BACKGROUND = [0xFE, 0xFB, 0xF7];

const DECOR = [0xDE, 0x62, 0x00];

const WEBP_QUALITY = 90;

$originals = $argv[1] ?? null;
$root = rtrim($argv[2] ?? dirname(__DIR__), '/');

if ($originals === null || ! is_file(rtrim($originals, '/').'/'.LOGO)) {
    fwrite(STDERR, 'Usage: php scripts/brand-assets.php /path/to/the/originals [/path/to/the/repository]'."\n".'The folder must hold "'.LOGO."\".\n");
    exit(1);
}

$source = imagecreatefrompng(rtrim($originals, '/').'/'.LOGO);

if ($source === false) {
    fwrite(STDERR, LOGO.": not a PNG\n");
    exit(1);
}

imagepalettetotruecolor($source);

foreach (['resources/images/brand', 'public/brand'] as $folder) {
    if (! is_dir("{$root}/{$folder}")) {
        mkdir("{$root}/{$folder}", 0775, true);
    }
}

// The word, without the arcs, transparent, with its pin opaque.
$word = crop($source, ...WORD);
[$region, $box] = pinRegion($word, PIN_SEED);
removeArcs($word, $region);
$wordmark = trimAlpha(whiteToAlpha($word, $region));

foreach (WORDMARK_HEIGHTS as $density => $height) {
    $light = scaleToHeight($wordmark, $height);
    save($light, "{$root}/resources/images/brand/wordmark-light-{$density}x.webp");
    save(recolourDark($light, CREAM), "{$root}/resources/images/brand/wordmark-dark-{$density}x.webp");
}

// The pin alone: its region, and the soft edge around it.
$pin = trimAlpha(whiteToAlpha(crop($word, $box[0], $box[1], $box[2] - $box[0] + 1, $box[3] - $box[1] + 1), shiftRegion($region, imagesx($word), $box), 2));

foreach (PIN_HEIGHTS as $density => $height) {
    save(scaleToHeight($pin, $height), "{$root}/resources/images/brand/pin-{$density}x.webp");
}

save(scaleToHeight($pin, PIN_HEIGHTS[3]), "{$root}/public/brand/pin.png");

// The square icons: the pin on brown, with round corners; the apple-touch one square and opaque
// (iOS rounds it itself).
$icons = [];

foreach (ICON_SIZES as $size) {
    $icons[$size] = icon($pin, $size, true);
    save($icons[$size], "{$root}/public/brand/icon-{$size}.png");
}

save(icon($pin, 180, false), "{$root}/public/apple-touch-icon.png");
writeIco([$icons[16], $icons[32], $icons[48]], "{$root}/public/favicon.ico");
report("{$root}/public/favicon.ico");

// The link preview: the wordmark in the middle of a cream page, the orange line under it.
$preview = imagecreatetruecolor(1200, 630);
imagefill($preview, 0, 0, colour($preview, BACKGROUND));
$mark = scaleToHeight($wordmark, 180);
$x = intdiv(1200 - imagesx($mark), 2);
$y = 205;
imagealphablending($preview, true);
imagecopy($preview, $mark, $x, $y, 0, 0, imagesx($mark), imagesy($mark));
$line = (int) round(imagesx($mark) * 0.6);
imagefilledrectangle($preview, intdiv(1200 - $line, 2), $y + 180 + 36, intdiv(1200 - $line, 2) + $line - 1, $y + 180 + 36 + 7, colour($preview, DECOR));
save($preview, "{$root}/public/og-default.png");

/**
 * A part of an image, as a new true-colour image.
 */
function crop(GdImage $image, int $x, int $y, int $width, int $height): GdImage
{
    $part = imagecreatetruecolor($width, $height);
    imagecopy($part, $image, 0, 0, $x, $y, $width, $height);

    return $part;
}

/**
 * Every orange or yellow pixel away from the pin becomes white: the arcs of the logo that cross
 * the letters. The brown of the letters is never warm enough to be touched.
 *
 * @param  array<int, true>  $pin
 */
function removeArcs(GdImage $image, array $pin): void
{
    $white = (int) imagecolorallocate($image, 255, 255, 255);
    $width = imagesx($image);

    for ($y = 0; $y < imagesy($image); $y++) {
        for ($x = 0; $x < $width; $x++) {
            [$r, $g, $b] = rgb(imagecolorat($image, $x, $y));
            [$hue, $lightness, $saturation] = hls($r, $g, $b);

            if ($saturation > 0.45 && $lightness > 0.3 && $hue <= 60 && ! near($pin, $width, $x, $y, 2)) {
                imagesetpixel($image, $x, $y, $white);
            }
        }
    }
}

/**
 * The pin: the orange pixels connected to $seed, plus what they enclose (the white centre and
 * the dot), as a set of "y * width + x" keys, and its box [x1, y1, x2, y2].
 *
 * @param  array{0: int, 1: int}  $seed
 * @return array{0: array<int, true>, 1: array{0: int, 1: int, 2: int, 3: int}}
 */
function pinRegion(GdImage $image, array $seed): array
{
    $width = imagesx($image);
    $height = imagesy($image);
    $warm = function (int $x, int $y) use ($image): bool {
        [$r, $g, $b] = rgb(imagecolorat($image, $x, $y));
        [$hue, $lightness, $saturation] = hls($r, $g, $b);

        return $saturation > 0.45 && $lightness > 0.3 && $hue <= 60;
    };

    if (! $warm(...$seed)) {
        fwrite(STDERR, "PIN_SEED is not inside the orange of the pin\n");
        exit(1);
    }

    $region = [$seed[1] * $width + $seed[0] => true];
    $queue = [$seed];
    [$x1, $y1, $x2, $y2] = [$seed[0], $seed[1], $seed[0], $seed[1]];

    while ($queue !== []) {
        [$x, $y] = array_pop($queue);

        foreach ([[$x + 1, $y], [$x - 1, $y], [$x, $y + 1], [$x, $y - 1]] as [$nx, $ny]) {
            if ($nx >= 0 && $ny >= 0 && $nx < $width && $ny < $height && ! isset($region[$ny * $width + $nx]) && $warm($nx, $ny)) {
                $region[$ny * $width + $nx] = true;
                $queue[] = [$nx, $ny];
                [$x1, $y1, $x2, $y2] = [min($x1, $nx), min($y1, $ny), max($x2, $nx), max($y2, $ny)];
            }
        }
    }

    // What the orange encloses: every pixel of the box that the border cannot reach.
    $outside = [];
    $queue = [];

    for ($x = $x1; $x <= $x2; $x++) {
        foreach ([$y1, $y2] as $y) {
            $queue[] = [$x, $y];
        }
    }

    for ($y = $y1; $y <= $y2; $y++) {
        foreach ([$x1, $x2] as $x) {
            $queue[] = [$x, $y];
        }
    }

    while ($queue !== []) {
        [$x, $y] = array_pop($queue);
        $key = $y * $width + $x;

        if ($x < $x1 || $x > $x2 || $y < $y1 || $y > $y2 || isset($region[$key]) || isset($outside[$key])) {
            continue;
        }

        $outside[$key] = true;
        array_push($queue, [$x + 1, $y], [$x - 1, $y], [$x, $y + 1], [$x, $y - 1]);
    }

    for ($y = $y1; $y <= $y2; $y++) {
        for ($x = $x1; $x <= $x2; $x++) {
            if (! isset($outside[$y * $width + $x])) {
                $region[$y * $width + $x] = true;
            }
        }
    }

    return [$region, [$x1, $y1, $x2, $y2]];
}

/**
 * The region of pinRegion() in the coordinates of a crop of the box, for an image of the box's
 * width.
 *
 * @param  array<int, true>  $region
 * @param  array{0: int, 1: int, 2: int, 3: int}  $box
 * @return array<int, true>
 */
function shiftRegion(array $region, int $width, array $box): array
{
    $boxWidth = $box[2] - $box[0] + 1;
    $shifted = [];

    foreach (array_keys($region) as $key) {
        [$x, $y] = [$key % $width, intdiv($key, $width)];
        $shifted[($y - $box[1]) * $boxWidth + ($x - $box[0])] = true;
    }

    return $shifted;
}

/**
 * The image cut to what is not transparent.
 */
function trimAlpha(GdImage $image): GdImage
{
    [$left, $top, $right, $bottom] = [imagesx($image), imagesy($image), -1, -1];

    for ($y = 0; $y < imagesy($image); $y++) {
        for ($x = 0; $x < imagesx($image); $x++) {
            if (((imagecolorat($image, $x, $y) >> 24) & 0x7F) < 127) {
                [$left, $top, $right, $bottom] = [min($left, $x), min($top, $y), max($right, $x), max($bottom, $y)];
            }
        }
    }

    $part = transparentCanvas($right - $left + 1, $bottom - $top + 1);
    imagecopy($part, $image, 0, 0, $left, $top, $right - $left + 1, $bottom - $top + 1);

    return $part;
}

/**
 * The transparent image at this height. GD weighs each colour by its alpha when it resamples,
 * so the invisible pixels never colour the edges.
 */
function scaleToHeight(GdImage $image, int $height): GdImage
{
    $width = (int) round(imagesx($image) * $height / imagesy($image));
    $scaled = transparentCanvas($width, $height);
    imagecopyresampled($scaled, $image, 0, 0, 0, 0, $width, $height, imagesx($image), imagesy($image));

    return $scaled;
}

/**
 * Colour to alpha against white: the alpha from the darkest channel, the colour un-mixed from
 * the white it was on. The pixels well inside $keep (2 px from its outer edge) stay opaque, with
 * their colour: the white centre of the pin too. Its outer edge, a mix of orange and white, loses
 * its white like the rest, so no light rim on a dark background. With $edge, only the
 * pixels at most that far from $keep are kept at all (the soft edge of the pin, not what is
 * around it).
 *
 * @param  array<int, true>  $keep
 */
function whiteToAlpha(GdImage $image, array $keep = [], ?int $edge = null): GdImage
{
    $width = imagesx($image);
    $out = transparentCanvas($width, imagesy($image));

    for ($y = 0; $y < imagesy($image); $y++) {
        for ($x = 0; $x < $width; $x++) {
            [$r, $g, $b] = rgb(imagecolorat($image, $x, $y));

            if (isset($keep[$y * $width + $x]) && within($keep, $width, $x, $y, 2)) {
                imagesetpixel($out, $x, $y, (int) imagecolorallocatealpha($out, $r, $g, $b, 0));

                continue;
            }

            if ($edge !== null && ! near($keep, $width, $x, $y, $edge)) {
                continue;
            }

            $alpha = 255 - min($r, $g, $b);

            if ($alpha <= 3) {
                continue;
            }

            $opacity = $alpha / 255;
            $unmix = fn (int $channel): int => (int) round(max(0, min(255, ($channel - 255 * (1 - $opacity)) / $opacity)));
            imagesetpixel($out, $x, $y, (int) imagecolorallocatealpha($out, $unmix($r), $unmix($g), $unmix($b), 127 - (int) round($opacity * 127)));
        }
    }

    return $out;
}

/**
 * Whether every pixel at most $distance away is in $region.
 *
 * @param  array<int, true>  $region
 */
function within(array $region, int $width, int $x, int $y, int $distance): bool
{
    for ($dy = -$distance; $dy <= $distance; $dy++) {
        for ($dx = -$distance; $dx <= $distance; $dx++) {
            if ($x + $dx < 0 || $x + $dx >= $width || ! isset($region[($y + $dy) * $width + $x + $dx])) {
                return false;
            }
        }
    }

    return true;
}

/**
 * Whether a pixel of $region is at most $distance away.
 *
 * @param  array<int, true>  $region
 */
function near(array $region, int $width, int $x, int $y, int $distance): bool
{
    for ($dy = -$distance; $dy <= $distance; $dy++) {
        for ($dx = -$distance; $dx <= $distance; $dx++) {
            if ($x + $dx >= 0 && $x + $dx < $width && isset($region[($y + $dy) * $width + $x + $dx])) {
                return true;
            }
        }
    }

    return false;
}

/**
 * The dark-theme wordmark: the brown of the letters and of the ring becomes the given colour,
 * the orange pin stays.
 *
 * @param  array{0: int, 1: int, 2: int}  $colour
 */
function recolourDark(GdImage $image, array $colour): GdImage
{
    $out = transparentCanvas(imagesx($image), imagesy($image));

    for ($y = 0; $y < imagesy($image); $y++) {
        for ($x = 0; $x < imagesx($image); $x++) {
            $pixel = imagecolorat($image, $x, $y);
            $alpha = ($pixel >> 24) & 0x7F;

            if ($alpha === 127) {
                continue;
            }

            [$r, $g, $b] = rgb($pixel);
            [, $lightness] = hls($r, $g, $b);
            [$r, $g, $b] = $lightness < 0.35 ? $colour : [$r, $g, $b];
            imagesetpixel($out, $x, $y, (int) imagecolorallocatealpha($out, $r, $g, $b, $alpha));
        }
    }

    return $out;
}

/**
 * The pin on a brown square, the pin 64% of its height. With round corners (their edge smoothed
 * pixel by pixel), or square and opaque.
 */
function icon(GdImage $pin, int $size, bool $rounded): GdImage
{
    $icon = imagecreatetruecolor($size, $size);
    imagefill($icon, 0, 0, colour($icon, BROWN));
    $mark = scaleToHeight($pin, max(8, (int) round($size * 0.64)));
    imagealphablending($icon, true);
    imagecopy($icon, $mark, intdiv($size - imagesx($mark), 2), intdiv($size - imagesy($mark), 2), 0, 0, imagesx($mark), imagesy($mark));

    if (! $rounded) {
        return $icon;
    }

    $out = transparentCanvas($size, $size, BROWN);
    $radius = $size * 0.22;

    for ($y = 0; $y < $size; $y++) {
        for ($x = 0; $x < $size; $x++) {
            // The distance from the centre of the nearest corner's circle, inside the corners only.
            $cx = min(max($x + 0.5, $radius), $size - $radius);
            $cy = min(max($y + 0.5, $radius), $size - $radius);
            $coverage = max(0.0, min(1.0, $radius - hypot($x + 0.5 - $cx, $y + 0.5 - $cy) + 0.5));
            [$r, $g, $b] = rgb(imagecolorat($icon, $x, $y));
            imagesetpixel($out, $x, $y, (int) imagecolorallocatealpha($out, $r, $g, $b, 127 - (int) round($coverage * 127)));
        }
    }

    return $out;
}

/**
 * A fully transparent true-colour image, its invisible pixels of the given colour (so that a
 * resize never brings another colour into the edges).
 *
 * @param  array{0: int, 1: int, 2: int}  $colour
 */
function transparentCanvas(int $width, int $height, array $colour = [255, 255, 255]): GdImage
{
    $image = imagecreatetruecolor($width, $height);
    imagealphablending($image, false);
    imagesavealpha($image, true);
    imagefill($image, 0, 0, (int) imagecolorallocatealpha($image, $colour[0], $colour[1], $colour[2], 127));

    return $image;
}

/**
 * Write a WebP or a PNG, by the extension, and print its size and weight.
 */
function save(GdImage $image, string $file): void
{
    imagesavealpha($image, true);
    str_ends_with($file, '.webp') ? imagewebp($image, $file, WEBP_QUALITY) : imagepng($image, $file, 9);
    report($file);
}

/**
 * An ICO file holding the given PNG images (Windows Vista and later, and every browser, read it).
 *
 * @param  list<GdImage>  $images
 */
function writeIco(array $images, string $file): void
{
    $header = pack('vvv', 0, 1, count($images));
    $entries = '';
    $data = '';
    $offset = 6 + 16 * count($images);

    foreach ($images as $image) {
        ob_start();
        imagesavealpha($image, true);
        imagepng($image, null, 9);
        $png = (string) ob_get_clean();
        $entries .= pack('CCCCvvVV', imagesx($image) % 256, imagesy($image) % 256, 0, 0, 1, 32, strlen($png), $offset);
        $data .= $png;
        $offset += strlen($png);
    }

    file_put_contents($file, $header.$entries.$data);
}

function report(string $file): void
{
    clearstatcache(true, $file);
    $size = getimagesize($file);
    printf("%s %s, %.1f KB\n", basename($file), $size === false ? '' : "{$size[0]}x{$size[1]}", (int) filesize($file) / 1024);
}

/**
 * @param  array{0: int, 1: int, 2: int}  $rgb
 */
function colour(GdImage $image, array $rgb): int
{
    return (int) imagecolorallocate($image, ...$rgb);
}

/**
 * @return array{0: int, 1: int, 2: int}
 */
function rgb(int $pixel): array
{
    return [($pixel >> 16) & 0xFF, ($pixel >> 8) & 0xFF, $pixel & 0xFF];
}

/**
 * Hue (degrees), lightness and saturation (0-1), as Python's colorsys.rgb_to_hls.
 *
 * @return array{0: float, 1: float, 2: float}
 */
function hls(int $r, int $g, int $b): array
{
    [$r, $g, $b] = [$r / 255, $g / 255, $b / 255];
    $max = max($r, $g, $b);
    $min = min($r, $g, $b);
    $lightness = ($max + $min) / 2;

    if ($max === $min) {
        return [0.0, $lightness, 0.0];
    }

    $delta = $max - $min;
    $saturation = $lightness <= 0.5 ? $delta / ($max + $min) : $delta / (2 - $max - $min);
    $hue = match ($max) {
        $r => fmod(($g - $b) / $delta, 6),
        $g => ($b - $r) / $delta + 2,
        default => ($r - $g) / $delta + 4,
    };

    return [fmod($hue * 60 + 360, 360), $lightness, $saturation];
}
