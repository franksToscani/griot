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
     * @throws AvatarNotStored when the new file cannot be written to the media disk
     */
    public function store(Provider $provider, UploadedFile $file): Provider
    {
        // One variant only: the smallest side asked is always made, at most the photo's own size.
        $contents = $this->encoder->encode($file, [self::MAX_SIDE], self::WEBP_QUALITY)[self::MAX_SIDE]->bytes;

        // A random name and the extension of the format chosen here: never the user's file name.
        $path = 'avatars/'.Str::random(40).'.webp';
        $oldPath = $provider->avatar_path;

        // Order: write the new file, then save the new path, and only then delete the old file.
        // If writing or saving fails, the old file and the old value stay, and the new file just
        // written is deleted: the provider never points to a missing file.
        // The disk too is made inside the try: in production, with the AWS_* variables empty, it
        // is refused already there. Every problem of the disk (not configured, the bucket, its
        // permissions, the network) is one AvatarNotStored, never an error page.
        try {
            $stored = MediaDisk::get()->put($path, $contents);
        } catch (Throwable $exception) {
            throw AvatarNotStored::because($exception);
        }

        if (! $stored) {
            throw AvatarNotStored::because();
        }

        $provider->avatar_path = $path;

        try {
            if (! $provider->save()) {
                throw new RuntimeException(sprintf('The avatar of provider %d was not saved.', $provider->id));
            }
        } catch (Throwable $exception) {
            // Quietly: a failed cleaning never hides the error of the database.
            MediaDisk::deleteQuietly($path, 'A new provider photo could not be removed from the media disk.');
            $provider->avatar_path = $oldPath;

            throw $exception;
        }

        // The new photo is saved: losing the old file only leaves a warning in the log.
        if ($oldPath !== null && $oldPath !== '') {
            MediaDisk::deleteQuietly($oldPath, 'The previous provider photo could not be removed from the media disk.');
        }

        return $provider;
    }
}
