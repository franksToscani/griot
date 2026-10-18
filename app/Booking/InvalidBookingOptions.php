<?php

namespace App\Booking;

use App\Models\Service;
use InvalidArgumentException;

/**
 * Some of the chosen options are not options of the booked service.
 *
 * It signals wrong input to the code, not a message for the customer: the request that
 * receives the options turns it into a translated validation error (issue #26, Sprint 3).
 */
final class InvalidBookingOptions extends InvalidArgumentException
{
    /**
     * @param  list<int>  $optionIds
     */
    public static function forService(Service $service, array $optionIds): self
    {
        return new self(sprintf(
            'Options [%s] are not options of service %d.',
            implode(', ', $optionIds),
            $service->id,
        ));
    }
}
