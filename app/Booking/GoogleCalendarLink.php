<?php

namespace App\Booking;

use App\Models\Booking;
use Carbon\CarbonInterface;

/**
 * The "Add to Google Calendar" link of a booking, for its provider (ProviderBookingResource,
 * docs/bookings.md): Google Calendar opens with the event already written, and the provider saves
 * it. No API, no key, no account linked: a plain address, which Google receives only when the
 * provider clicks it (docs/legal.md).
 *
 * When there is an event and what it holds come from CalendarEvent, shared with the .ics file
 * (IcsFile): service and customer's name, the times, the booking in the provider's area by its id
 * and the options. Never the price, the notes, the phone, the email, the reason of a
 * cancellation or the customer's private link: neither class reads the booking's secret code.
 *
 * The times are UTC instants with a "Z", so a change of the clocks needs nothing; ctz is the
 * booking time zone, the zone the saved event shows. The format of the link is not documented by
 * Google: it is the one used for years by the "add to calendar" buttons.
 */
final class GoogleCalendarLink
{
    public const BASE = 'https://calendar.google.com/calendar/render';

    /**
     * The link, or null when the button must not be shown. Expects service and options loaded.
     */
    public static function for(Booking $booking): ?string
    {
        $event = CalendarEvent::for($booking);

        if ($event === null) {
            return null;
        }

        return self::BASE.'?'.http_build_query([
            'action' => 'TEMPLATE',
            'text' => $event->title,
            'dates' => self::utc($event->start).'/'.self::utc($event->end),
            'ctz' => config()->string('booking.timezone'),
            'details' => $event->description(),
        ], '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * An instant as Google reads it in UTC: 20261119T130000Z.
     */
    private static function utc(CarbonInterface $instant): string
    {
        return $instant->format('Ymd\THis\Z');
    }
}
