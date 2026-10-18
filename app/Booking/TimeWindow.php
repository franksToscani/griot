<?php

namespace App\Booking;

use Carbon\CarbonImmutable;

/**
 * A span of time, from start to end, both in UTC. It cannot be changed once created.
 */
final readonly class TimeWindow
{
    public function __construct(
        public CarbonImmutable $start,
        public CarbonImmutable $end,
    ) {}
}
