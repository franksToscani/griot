<?php

namespace App\Profile;

use App\Models\Provider;
use App\Models\ProviderPhoto;
use App\Support\MediaDisk;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Adds a photo to the provider's gallery (issue #143, docs/gallery.md): every size of
 * config('booking.gallery.sizes'), as WebP, at gallery/{name}-{side}.webp on the media disk, and
 * its row, last in the order. At most config('booking.gallery.max_photos') photos.
 *
 * Either everything is saved or nothing is: if a file or the row fails, the files already
 * written are removed.
 */
final class StoreProviderPhoto
{
    public function __construct(private readonly WebpEncoder $encoder) {}

    /**
     * @throws InvalidAvatar when the file cannot be read or written as an image
     * @throws GalleryFull when the gallery already has the most photos allowed
     * @throws PhotoNotStored when a size cannot be written to the disk
     */
    public function store(Provider $provider, UploadedFile $file, ?string $altText): ProviderPhoto
    {
        // The slow part first, outside the lock: decoding and writing the sizes in memory.
        // Only whole numbers from the configuration: the sizes are pixels.
        $sizes = array_values(array_filter(config()->array('booking.gallery.sizes'), 'is_int'));
        $variants = $this->encoder->encode($file, $sizes, config()->integer('booking.gallery.quality'));
        // The encoder makes the largest last; nothing at all only with no sizes configured.
        $largest = end($variants);

        if (! $largest instanceof WebpVariant) {
            throw InvalidAvatar::notEncoded();
        }
        $name = Str::random(40);
        $disk = MediaDisk::get();
        $written = [];

        try {
            return DB::transaction(function () use ($provider, $variants, $largest, $name, $altText, $disk, &$written): ProviderPhoto {
                // The first statement: two uploads of the same provider wait for each other here,
                // so the second one sees the photo of the first. From here on only $locked is used.
                $locked = Provider::query()->whereKey($provider->id)->lockForUpdate()->firstOrFail();
                $max = config()->integer('booking.gallery.max_photos');

                if ($locked->photos()->count() >= $max) {
                    throw GalleryFull::for($locked, $max);
                }

                foreach ($variants as $side => $variant) {
                    $path = "gallery/{$name}-{$side}.webp";

                    try {
                        $stored = $disk->put($path, $variant->bytes);
                    } catch (Throwable $exception) {
                        throw PhotoNotStored::because($exception);
                    }

                    if (! $stored) {
                        throw PhotoNotStored::because();
                    }

                    $written[] = $path;
                }

                $photo = new ProviderPhoto(['alt_text' => $altText]);
                // Set by the code, never by the input.
                $photo->forceFill([
                    'name' => $name,
                    'width' => $largest->width,
                    'height' => $largest->height,
                    'position' => (int) $locked->photos()->max('position') + 1,
                    'consent_at' => now(),
                ]);
                $locked->photos()->save($photo);

                return $photo;
            });
        } catch (Throwable $exception) {
            // Nothing of the photo is left: no row (the transaction), no file.
            foreach ($written as $path) {
                $disk->delete($path);
            }

            throw $exception;
        }
    }
}
