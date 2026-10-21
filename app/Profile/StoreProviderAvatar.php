<?php

namespace App\Profile;

use App\Models\Provider;
use GdImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Saves the provider's photo. The uploaded file is never stored as it is: GD decodes it and
 * writes a new image, so only the pixels survive. Every metadata of the original (EXIF with
 * the GPS position, comments, colour profiles) is dropped, and what reaches the disk is always
 * a WebP chosen by the code, whatever the user sent.
 *
 * It never touches is_published, slug or the profile fields.
 */
final class StoreProviderAvatar
{
    /**
     * The longest side of the saved photo, in pixels. Smaller photos are not enlarged.
     */
    private const MAX_SIDE = 512;

    private const WEBP_QUALITY = 85;

    /**
     * @throws InvalidAvatar when the file cannot be read or written as an image
     */
    public function store(Provider $provider, UploadedFile $file): Provider
    {
        $contents = $this->encode($file);

        $disk = Storage::disk('public');
        // A random name and the extension of the format chosen here: never the user's file name.
        $path = 'avatars/'.Str::random(40).'.webp';
        $oldPath = $provider->avatar_path;

        // Order: write the new file, then save the new path, and only then delete the old file.
        // If writing or saving fails, the old file and the old value stay, and the new file just
        // written is deleted: the provider never points to a missing file.
        if (! $disk->put($path, $contents)) {
            // A problem of the disk, not of the user's file: it propagates as an error.
            throw new RuntimeException('The avatar could not be written to the public disk.');
        }

        $provider->avatar_path = $path;

        try {
            if (! $provider->save()) {
                throw new RuntimeException(sprintf('The avatar of provider %d was not saved.', $provider->id));
            }
        } catch (Throwable $exception) {
            $disk->delete($path);
            $provider->avatar_path = $oldPath;

            throw $exception;
        }

        if ($oldPath !== null && $oldPath !== '' && $disk->exists($oldPath)) {
            $disk->delete($oldPath);
        }

        return $provider;
    }

    /**
     * The uploaded image, upright, at most MAX_SIDE on the long side, as WebP bytes.
     */
    private function encode(UploadedFile $file): string
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
        $scale = min(1, self::MAX_SIDE / max($width, $height));
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
        $written = imagewebp($canvas, null, self::WEBP_QUALITY);
        $webp = ob_get_clean();

        if (! $written || ! is_string($webp) || $webp === '') {
            throw InvalidAvatar::notEncoded();
        }

        return $webp;
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
