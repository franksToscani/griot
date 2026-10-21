<?php

namespace App\Profile;

use App\Models\Service;
use RuntimeException;

/**
 * The service cannot be deleted: it has bookings, or one of its options was chosen in a booking.
 * It happens in normal use; the request turns it into a message that suggests to deactivate it.
 * The technical message has the service id and the reason only, no personal data.
 */
final class CannotDeleteService extends RuntimeException
{
    /**
     * @param  'bookings'|'booked_options'  $reason
     */
    private function __construct(string $message, private readonly string $reason)
    {
        parent::__construct($message);
    }

    /**
     * @param  'bookings'|'booked_options'  $reason
     */
    public static function because(Service $service, string $reason): self
    {
        return new self(sprintf('Service %d cannot be deleted: %s.', $service->id, $reason), $reason);
    }

    /**
     * Why it cannot be deleted: "bookings" or "booked_options".
     *
     * @return 'bookings'|'booked_options'
     */
    public function reason(): string
    {
        return $this->reason;
    }
}
