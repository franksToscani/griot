<?php

namespace App\Profile;

use RuntimeException;
use Throwable;

/**
 * The provider's photo could not be written to the media disk: a disk that is not configured
 * (AWS_* empty), the bucket, its permissions, the network. Nothing changes for the provider: the
 * request turns it into a "try again later" message. As PhotoNotStored for the gallery. The
 * technical message has no personal data and no path.
 */
final class AvatarNotStored extends RuntimeException
{
    public static function because(?Throwable $previous = null): self
    {
        return new self('The avatar could not be written to the media disk.', 0, $previous);
    }
}
