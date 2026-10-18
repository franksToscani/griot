<?php

namespace App\Booking;

use App\Models\Provider;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use RuntimeException;

/**
 * The chosen start is not a free slot any more, or never was.
 *
 * It is not wrong input from the code: it happens in normal use, for example when two customers
 * choose the same time. It has no message for the customer: the request that creates the
 * booking (issue #27) turns it into a translated message with the updated slots.
 */
final class SlotNotAvailable extends RuntimeException
{
    public static function at(Provider $provider, CarbonInterface $startsAt): self
    {
        return new self(sprintf(
            'The slot starting at %s is not available for provider %d.',
            CarbonImmutable::instance($startsAt)->utc()->format('Y-m-d\TH:i:s\Z'),
            $provider->id,
        ));
    }
}
