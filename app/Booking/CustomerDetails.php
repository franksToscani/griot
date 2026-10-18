<?php

namespace App\Booking;

/**
 * Who is booking. Only data: the request that receives it (issue #27) validates it.
 */
final readonly class CustomerDetails
{
    public function __construct(
        public string $name,
        public string $phone,
        public ?string $email = null,
        public ?string $notes = null,
    ) {}
}
