<?php

namespace App\Profile;

use App\Models\Provider;
use RuntimeException;

/**
 * The provider already has the most photos allowed in the gallery. It happens in normal use; the
 * request turns it into a message. The technical message has ids and numbers only.
 */
final class GalleryFull extends RuntimeException
{
    public static function for(Provider $provider, int $max): self
    {
        return new self(sprintf('Provider %d already has %d gallery photos.', $provider->id, $max));
    }
}
