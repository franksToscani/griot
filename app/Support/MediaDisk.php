<?php

namespace App\Support;

use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * The disk of the site's images: the one place that names it (config/filesystems.php, "media").
 * Locally a folder served by the public/storage link; in production an S3-compatible bucket
 * (docs/media-storage.md). The code never writes Storage::disk('media') by hand.
 */
final class MediaDisk
{
    public const NAME = 'media';

    /**
     * The disk. In production it is made here, at the first use: with the AWS_* variables empty
     * the S3 client is refused already at this point (no region), so a caller that must not fail
     * calls it inside its own try.
     */
    public static function get(): FilesystemAdapter
    {
        return Storage::disk(self::NAME);
    }

    /**
     * A file of the disk, removed without ever failing: a disk that is not configured, an error of
     * the bucket or of the network, or a "false" answer only leave $warning in the log, with the
     * class of the error and nothing else (no path, no name, no email). For the files whose loss
     * must not undo what the provider did: an old photo, a photo removed, a failed upload cleaned.
     */
    public static function deleteQuietly(string $path, string $warning): void
    {
        try {
            $deleted = self::get()->delete($path);
        } catch (Throwable $exception) {
            Log::warning($warning, ['exception' => $exception::class]);

            return;
        }

        if (! $deleted) {
            Log::warning($warning);
        }
    }
}
