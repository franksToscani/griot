<?php

namespace App\Profile;

use App\Models\Provider;
use App\Models\ProviderPhoto;
use App\Support\MediaDisk;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Removes a photo from the provider's gallery (issue #143): the row first, with the positions of
 * the others made 1, 2, 3… again in the same transaction, then the files of every size. A file
 * that cannot be removed does not undo the deletion: a warning in the log, with no personal data.
 */
final class DeleteProviderPhoto
{
    public function delete(ProviderPhoto $photo): void
    {
        $paths = $photo->paths();

        DB::transaction(function () use ($photo): void {
            // The first statement: an upload or another deletion of the same provider waits here.
            $locked = Provider::query()->whereKey($photo->provider_id)->lockForUpdate()->firstOrFail();

            $locked->photos()->whereKey($photo->id)->delete();

            foreach ($locked->photos()->get() as $index => $other) {
                if ($other->position !== $index + 1) {
                    $other->forceFill(['position' => $index + 1])->save();
                }
            }
        });

        foreach ($paths as $path) {
            self::removeFile($path);
        }
    }

    /**
     * A file of the media disk, removed; a failure is only a warning, with the class of the error
     * and nothing else (no path, no name, no email).
     */
    public static function removeFile(string $path): void
    {
        try {
            $deleted = MediaDisk::get()->delete($path);
        } catch (Throwable $exception) {
            Log::warning('A gallery photo could not be removed from the media disk.', ['exception' => $exception::class]);

            return;
        }

        if (! $deleted) {
            Log::warning('A gallery photo could not be removed from the media disk.');
        }
    }
}
