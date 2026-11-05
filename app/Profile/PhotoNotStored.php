<?php

namespace App\Profile;

use RuntimeException;
use Throwable;

/**
 * A size of a gallery photo could not be written to the media disk (a full disk, the network to
 * the bucket). Nothing of the photo is left: the request turns it into a "try again" message.
 * The technical message has no personal data and no path.
 */
final class PhotoNotStored extends RuntimeException
{
    public static function because(?Throwable $previous = null): self
    {
        return new self('A size of a gallery photo could not be written to the media disk.', 0, $previous);
    }
}
