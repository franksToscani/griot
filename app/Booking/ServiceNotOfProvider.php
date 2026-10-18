<?php

namespace App\Booking;

use App\Models\Provider;
use App\Models\Service;
use InvalidArgumentException;

/**
 * The booked service is not one of the provider's services.
 *
 * It signals wrong input to the code, not a message for the customer: the request that
 * receives the service turns it into a translated validation error (issue #26, Sprint 3).
 */
final class ServiceNotOfProvider extends InvalidArgumentException
{
    public static function forProvider(Service $service, Provider $provider): self
    {
        return new self(sprintf(
            'Service %d is not a service of provider %d.',
            $service->id,
            $provider->id,
        ));
    }
}
