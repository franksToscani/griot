<?php

namespace App\Profile;

use App\Models\Provider;
use App\Support\MediaDisk;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Saves the provider's photo. The uploaded file is never stored as it is: WebpEncoder decodes it
 * and writes a new image, so only the pixels survive and every metadata is dropped. What reaches
 * the disk is always one WebP chosen by the code, whatever the user sent.
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

    public function __construct(private readonly WebpEncoder $encoder) {}

    /**
     * @throws InvalidAvatar when the file cannot be read or written as an image
     */
    public function store(Provider $provider, UploadedFile $file): Provider
    {
        // One variant only: the smallest side asked is always made, at most the photo's own size.
        $contents = $this->encoder->encode($file, [self::MAX_SIDE], self::WEBP_QUALITY)[self::MAX_SIDE]->bytes;

        $disk = MediaDisk::get();
        // A random name and the extension of the format chosen here: never the user's file name.
        $path = 'avatars/'.Str::random(40).'.webp';
        $oldPath = $provider->avatar_path;

        // Order: write the new file, then save the new path, and only then delete the old file.
        // If writing or saving fails, the old file and the old value stay, and the new file just
        // written is deleted: the provider never points to a missing file.
        if (! $disk->put($path, $contents)) {
            // A problem of the disk, not of the user's file: it propagates as an error.
            throw new RuntimeException('The avatar could not be written to the media disk.');
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
}
