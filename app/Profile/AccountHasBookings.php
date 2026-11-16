<?php

namespace App\Profile;

use RuntimeException;

/**
 * The account of a provider with bookings cannot be deleted (issue #133): bookings.provider_id
 * and bookings.service_id are restrictOnDelete, for any booking, anonymous ones included. It
 * happens in normal use; the request turns it into a message with the site's contact. The
 * technical message has the user id only, no personal data.
 */
final class AccountHasBookings extends RuntimeException
{
    public static function forUser(int $userId): self
    {
        return new self(sprintf('The account of user %d has bookings and cannot be deleted.', $userId));
    }
}
