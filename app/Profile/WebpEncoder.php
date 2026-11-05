<?php

namespace App\Profile;

use GdImage;
use Illuminate\Http\UploadedFile;

/**
 * Turns an uploaded photo into new WebP images, with GD. The file is never kept as it is: GD
 * decodes it and writes new images, so only the pixels survive. Every metadata of the original
 * (EXIF with the GPS position, comments, colour profiles) is dropped. A JPEG taken with the phone
 * on its side is turned upright first.
 *
 * Used by the provider's photo (one variant) and by the gallery (several, issue #143).
 */
final class WebpEncoder
{
    /**
     * WebP images of the photo, one per longest side asked, keyed by that side, smallest first.
     *
     * A photo is never enlarged: a side longer than the photo's own longest side is not made,
     * except the smallest one asked, which is always there (at most the photo's own size), so
     * that there is always one image.
     *
     * @param  list<int>  $longestSides
     * @return array<int, WebpVariant>
     *
     * @throws InvalidAvatar when the file cannot be read or written as an image
     */
    public function encode(UploadedFile $file, array $longestSides, int $quality): array
    {
        $bytes = $file->get();

        // "@": GD reports unreadable data with a PHP warning, which Laravel would turn into an
        // exception; false is the answer that matters here.
        $source = is_string($bytes) && $bytes !== '' ? @imagecreatefromstring($bytes) : false;

        if (! $source instanceof GdImage) {
            throw InvalidAvatar::unreadable();
        }

        $source = $this->upright($source, $bytes, $file);
        $width = imagesx($source);
        $height = imagesy($source);
        $longest = max($width, $height);

        $sides = array_values(array_unique($longestSides));
        sort($sides);

        $variants = [];

        foreach ($sides as $position => $side) {
            if ($position > 0 && $side > $longest) {
                break;
            }

            $variants[$side] = $this->resized($source, $width, $height, $side, $quality);
        }

        // No reference left: PHP frees the GD image here (imagedestroy() does nothing since PHP 8).
        unset($source);

        return $variants;
    }

    /**
     * The photo with its longest side at most $side, as WebP.
     */
    private function resized(GdImage $source, int $width, int $height, int $side, int $quality): WebpVariant
    {
        $scale = min(1, $side / max($width, $height));
        $newWidth = max(1, (int) round($width * $scale));
        $newHeight = max(1, (int) round($height * $scale));

        // A new true-colour canvas: it also turns palette PNGs into true colour, which WebP needs.
        $canvas = imagecreatetruecolor($newWidth, $newHeight);

        // Keep the transparency of PNG and WebP: no blending, a transparent background, and the
        // alpha channel saved in the output.
        imagealphablending($canvas, false);
        imagesavealpha($canvas, true);
        imagefill($canvas, 0, 0, (int) imagecolorallocatealpha($canvas, 0, 0, 0, 127));
        imagecopyresampled($canvas, $source, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);

        ob_start();
        $written = imagewebp($canvas, null, $quality);
        $webp = ob_get_clean();
        unset($canvas);

        if (! $written || ! is_string($webp) || $webp === '') {
            throw InvalidAvatar::notEncoded();
        }

        return new WebpVariant($webp, $newWidth, $newHeight);
    }

    /**
     * Turn a JPEG taken with the phone on its side the right way up, using its EXIF orientation.
     * Only the rotations cameras write (3, 6, 8) are handled; the mirrored ones are left as they are.
     */
    private function upright(GdImage $image, string $bytes, UploadedFile $file): GdImage
    {
        $info = getimagesizefromstring($bytes);

        if ($info === false || $info[2] !== IMAGETYPE_JPEG || ! function_exists('exif_read_data')) {
            return $image;
        }

        // "@": a broken EXIF block gives a warning; without data the image stays as it is.
        $exif = @exif_read_data($file->getRealPath());
        $orientation = is_array($exif) ? ($exif['Orientation'] ?? 1) : 1;

        // imagerotate() turns counter-clockwise.
        $rotated = match ($orientation) {
            3 => imagerotate($image, 180, 0),
            6 => imagerotate($image, -90, 0),
            8 => imagerotate($image, 90, 0),
            default => $image,
        };

        return $rotated instanceof GdImage ? $rotated : $image;
    }
}
