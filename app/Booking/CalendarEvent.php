<?php

namespace App\Booking;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\ServiceOption;
use Carbon\CarbonImmutable;

/**
 * The event of a booking in the provider's own calendar (docs/bookings.md), shared by the "Add to
 * Google Calendar" link (GoogleCalendarLink) and the .ics file (IcsFile): when it exists, and
 * what it holds. Written once, so the two never differ.
 *
 * Only for a confirmed booking that is not anonymous and has not started yet (compared with now(),
 * which the tests can freeze); a booking the provider wrote is born confirmed. The event holds the
 * service and the customer's name (title), the start and the end as UTC instants, the address of
 * the booking in the provider's area (by its id, behind the login) and the names of the chosen
 * options, in the language of the provider's page. Never the price, the notes, the phone, the
 * email, the reason of a cancellation or the customer's private link: this class never reads the
 * booking's secret code.
 */
final readonly class CalendarEvent
{
    /**
     * @param  list<string>  $details  the lines of the description
     */
    private function __construct(
        public int $bookingId,
        public string $title,
        public array $details,
        public CarbonImmutable $start,
        public CarbonImmutable $end,
    ) {}

    /**
     * The event, or null when the booking must not have one. Expects service and options loaded.
     */
    public static function for(Booking $booking): ?self
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

        return new self(
            $booking->id,
            $title,
            $details,
            CarbonImmutable::instance($booking->starts_at)->utc(),
            CarbonImmutable::instance($booking->ends_at)->utc(),
        );
    }

    /**
     * The description, one detail a line.
     */
    public function description(): string
    {
        return implode("\n", $this->details);
    }
}
