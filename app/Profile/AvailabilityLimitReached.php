<?php

namespace App\Profile;

use App\Models\Provider;
use RuntimeException;

/**
 * The day already has the most time slots allowed. It happens in normal use; the request turns it
 * into a message. The technical message has ids and numbers only, no personal data.
 */
final class AvailabilityLimitReached extends RuntimeException
{
    public static function on(Provider $provider, int $weekday, int $max): self
    {
        return new self(sprintf('Provider %d already has %d availabilities on weekday %d.', $provider->id, $max, $weekday));
    }
}
