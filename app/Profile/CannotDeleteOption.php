<?php

namespace App\Profile;

use App\Models\ServiceOption;
use RuntimeException;

/**
 * The option cannot be deleted: it was chosen in a booking. It happens in normal use; the request
 * turns it into a message. The technical message has the option id and the reason only, no
 * personal data.
 */
final class CannotDeleteOption extends RuntimeException
{
    /**
     * @param  'bookings'  $reason
     */
    private function __construct(string $message, private readonly string $reason)
    {
        parent::__construct($message);
    }

    /**
     * @param  'bookings'  $reason
     */
    public static function because(ServiceOption $option, string $reason): self
    {
        return new self(sprintf('Service option %d cannot be deleted: %s.', $option->id, $reason), $reason);
    }

    /**
     * Why it cannot be deleted: "bookings".
     *
     * @return 'bookings'
     */
    public function reason(): string
    {
        return $this->reason;
    }
}
