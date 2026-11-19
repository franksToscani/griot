<?php

namespace App\Booking;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\ServiceOption;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * The "Add to Google Calendar" link of a booking, for its provider (ProviderBookingResource,
 * docs/bookings.md): Google Calendar opens with the event already written, and the provider saves
 * it. No API, no key, no account linked: a plain address, which Google receives only when the
 * provider clicks it (docs/legal.md).
 *
 * The event holds the service and the customer's name (title), the time, the address of the
 * booking in the provider's area (by its id, behind the login) and the names of the chosen options.
 * Never the price, the notes, the phone, the email, the reason of a cancellation or the
 * customer's private link: this class never reads the booking's secret code.
 *
 * Only for a confirmed booking that is not anonymous and has not started yet (compared with now(),
 * which the tests can freeze). The times are UTC instants with a "Z", so a change of the clocks
 * needs nothing; ctz is the booking time zone, the zone the saved event shows. The format of the
 * link is not documented by Google: it is the one used for years by the "add to calendar" buttons.
 */
final class GoogleCalendarLink
{
    public const BASE = 'https://calendar.google.com/calendar/render';

    /**
     * The link, or null when the button must not be shown. Expects service and options loaded.
     */
    public static function for(Booking $booking): ?string
    {
        if ($booking->status !== BookingStatus::Confirmed
            || $booking->isAnonymized()
            || ! $booking->starts_at->isAfter(CarbonImmutable::now())) {
            return null;
        }

        $title = $booking->customer_name === null
            ? $booking->service->name
            : $booking->service->name.' – '.$booking->customer_name;

        $details = [__('provider.bookings.calendar_details', [
            'url' => route('provider.bookings.show', ['booking' => $booking->id]),
        ])];

        $options = $booking->options->sortBy('id')->map(fn (ServiceOption $option) => $option->name);

        if ($options->isNotEmpty()) {
            $details[] = __('provider.bookings.calendar_options', ['options' => $options->implode(', ')]);
        }

        return self::BASE.'?'.http_build_query([
            'action' => 'TEMPLATE',
            'text' => $title,
            'dates' => self::utc($booking->starts_at).'/'.self::utc($booking->ends_at),
            'ctz' => config()->string('booking.timezone'),
            'details' => implode("\n", $details),
        ], '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * An instant as Google reads it in UTC: 20261119T130000Z.
     */
    private static function utc(CarbonInterface $instant): string
    {
        return CarbonImmutable::instance($instant)->utc()->format('Ymd\THis\Z');
    }
}
