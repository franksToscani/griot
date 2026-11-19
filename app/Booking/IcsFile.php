<?php

namespace App\Booking;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * The .ics file of a booking (RFC 5545), for Apple Calendar, Outlook and every calendar that opens
 * one (ProviderBookingCalendarController, docs/bookings.md). The event is CalendarEvent, the same
 * as the "Add to Google Calendar" link: when it exists and what it holds are decided there. The
 * file is downloaded on the provider's own device: no other service receives anything.
 *
 * - One VEVENT, with a stable UID made of the booking's id and the application's host: adding the
 *   same booking again updates the event instead of doubling it.
 * - DTSTART and DTEND are UTC instants with a "Z": no time zone block, no change of the clocks to
 *   handle. DTSTAMP is the moment the file is made.
 * - Text values are escaped (backslash, comma, semicolon, new line) and every line ends with CRLF.
 * - A line longer than 75 bytes is folded (CRLF and a space), cutting between characters only:
 *   an accent or an emoji is never split across two lines.
 */
final class IcsFile
{
    public const PRODID = '-//Griot//Prenotazioni//IT';

    /**
     * The longest line, in bytes, CRLF excluded (RFC 5545, 3.1).
     */
    public const LINE_BYTES = 75;

    /**
     * The file of an event. A booking without an event has no file: the controller answers 404.
     */
    public static function for(CalendarEvent $event): string
    {
        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:'.self::PRODID,
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
            'BEGIN:VEVENT',
            'UID:'.self::uid($event),
            'DTSTAMP:'.self::utc(CarbonImmutable::now()),
            'DTSTART:'.self::utc($event->start),
            'DTEND:'.self::utc($event->end),
            'SUMMARY:'.self::text($event->title),
            'DESCRIPTION:'.self::text($event->description()),
            'END:VEVENT',
            'END:VCALENDAR',
        ];

        return implode('', array_map(fn (string $line) => self::fold($line)."\r\n", $lines));
    }

    /**
     * The same for every download of the same booking: "booking-123@griot.example.test".
     */
    public static function uid(CalendarEvent $event): string
    {
        $host = parse_url(config()->string('app.url'), PHP_URL_HOST);

        return 'booking-'.$event->bookingId.'@'.(is_string($host) && $host !== '' ? $host : 'griot');
    }

    /**
     * A TEXT value (RFC 5545, 3.3.11): backslash first, then comma, semicolon and new lines.
     */
    public static function text(string $value): string
    {
        return str_replace(
            ['\\', ',', ';', "\r\n", "\r", "\n"],
            ['\\\\', '\\,', '\\;', '\\n', '\\n', '\\n'],
            $value,
        );
    }

    /**
     * A line folded at 75 bytes (RFC 5545, 3.1): the next part starts with one space, which counts
     * in its 75 bytes. mb_strcut() cuts at a byte length without splitting a UTF-8 character.
     */
    public static function fold(string $line): string
    {
        $parts = [];
        $limit = self::LINE_BYTES;

        while (strlen($line) > $limit) {
            $part = mb_strcut($line, 0, $limit, 'UTF-8');
            $parts[] = $part;
            $line = substr($line, strlen($part));
            // The following parts start with a space.
            $limit = self::LINE_BYTES - 1;
        }

        $parts[] = $line;

        return implode("\r\n ", $parts);
    }

    private static function utc(CarbonInterface $instant): string
    {
        return CarbonImmutable::instance($instant)->utc()->format('Ymd\THis\Z');
    }
}
