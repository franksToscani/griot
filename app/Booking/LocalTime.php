<?php

namespace App\Booking;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use LogicException;

/**
 * The provider's local time, in the booking time zone, in both directions: a UTC instant shown as
 * a local time, and a local calendar day turned into its limits in UTC. Neither the server's nor
 * the browser's time zone matters.
 *
 * BookingConfirmationResource and NewBookingNotification still apply the same rule on their own.
 */
final class LocalTime
{
    /**
     * The instant in the booking time zone, for example 07:00 UTC in summer is 09:00 in Italy.
     */
    public static function fromUtc(CarbonInterface $instant): CarbonImmutable
    {
        return CarbonImmutable::instance($instant)->setTimezone(config()->string('booking.timezone'));
    }

    /**
     * The UTC instant at which the local day "Y-m-d" begins: its local midnight.
     */
    public static function dayStart(string $date): CarbonImmutable
    {
        return self::localMidnight($date)->utc();
    }

    /**
     * The UTC instant at which the local day "Y-m-d" ends, excluded: the local midnight of the next
     * day. The day is 23 or 25 hours long when the clocks change, never computed as 24 hours.
     */
    public static function dayEnd(string $date): CarbonImmutable
    {
        return self::localMidnight($date)->addDay()->utc();
    }

    private static function localMidnight(string $date): CarbonImmutable
    {
        // "!" sets the time to midnight: only the calendar date is read.
        return CarbonImmutable::createFromFormat('!Y-m-d', $date, config()->string('booking.timezone'))
            ?: throw new LogicException('The date is not in the Y-m-d format.');
    }
}
