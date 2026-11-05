<?php

namespace App\Support;

use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;

/**
 * The disk of the site's images: the one place that names it (config/filesystems.php, "media").
 * Locally a folder served by the public/storage link; in production an S3-compatible bucket
 * (docs/media-storage.md). The code never writes Storage::disk('media') by hand.
 */
final class MediaDisk
{
    public const NAME = 'media';

    public static function get(): FilesystemAdapter
    {
        return Storage::disk(self::NAME);
    }
}
