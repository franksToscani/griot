<?php

namespace App\Profile;

use App\Models\Provider;
use RuntimeException;

/**
 * The time slot overlaps another one of the same provider on the same day. It happens in normal
 * use; the request turns it into a message. The technical message has ids and the day only, no
 * personal data.
 */
final class AvailabilityOverlaps extends RuntimeException
{
    public static function on(Provider $provider, int $weekday): self
    {
        return new self(sprintf('The availability overlaps another one of provider %d on weekday %d.', $provider->id, $weekday));
    }
}
