<?php

namespace App\Booking;

use App\Models\Booking;
use RuntimeException;

/**
 * The booking can no longer be cancelled by the customer: the cancellation notice has passed,
 * or the booking has already started.
 *
 * It happens in normal use, for example when the customer opens the link too late. It has no
 * message for the customer: the request turns it into a translated message. The technical
 * message never contains the public_token.
 */
final class CancellationNotAllowed extends RuntimeException
{
    public static function for(Booking $booking): self
    {
        return new self(sprintf(
            'Booking %d (%s, starting at %s) can no longer be cancelled by the customer.',
            $booking->id,
            $booking->status->value,
            $booking->starts_at->utc()->format('Y-m-d\TH:i:s\Z'),
        ));
    }
}
