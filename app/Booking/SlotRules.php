<?php

namespace App\Booking;

/**
 * The rules that change with who is booking. Everything else is the same for the customer and the
 * provider (published provider, active service, booking window, time windows, grid, overlaps): it
 * lives in FreeSlots and is not repeated here.
 */
final readonly class SlotRules
{
    private function __construct(
        // A slot can be booked only if it starts at least this many minutes from now. Zero still
        // refuses a start in the past.
        public int $minimumNoticeMinutes,
    ) {}

    /**
     * A customer from the public page: the provider needs time to see the request.
     */
    public static function forCustomer(): self
    {
        return new self(config()->integer('booking.minimum_notice_minutes'));
    }

    /**
     * The provider writing a booking in the own agenda: no notice, since the provider already knows.
     */
    public static function forProvider(): self
    {
        return new self(0);
    }
}
