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
 * The long date and the time range of the emails and of the WhatsApp text are written here only.
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
     * The local day written in full, for example "giovedì 29 ottobre 2026" or "jeudi 29 octobre
     * 2026": in the given language, or in the language of the application when none is given.
     */
    public static function longDate(CarbonInterface $instant, ?string $locale = null): string
    {
        $local = self::fromUtc($instant);

        // settings() gives a copy in that language; the application's language does not change.
        return ($locale === null ? $local : $local->settings(['locale' => $locale]))->isoFormat('dddd D MMMM YYYY');
    }

    /**
     * The local start and end, for example "09:00–11:45": the same in every language.
     */
    public static function timeRange(CarbonInterface $start, CarbonInterface $end): string
    {
        return self::fromUtc($start)->format('H:i').'–'.self::fromUtc($end)->format('H:i');
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
