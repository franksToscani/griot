<?php

namespace App\Http\Resources;

use App\Booking\LocalTime;
use App\Booking\TimeWindow;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A bookable slot: its start in UTC, which identifies it, the same instant as the provider's
 * local time, and its latest end in local time, computed here because the customer's browser time
 * zone is irrelevant.
 *
 * @mixin TimeWindow
 */
class SlotResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            // For example "2026-10-19T07:00:00Z".
            'start' => $this->start->utc()->format('Y-m-d\TH:i:s\Z'),
            // For example "09:00".
            'time' => $this->start->setTimezone(config()->string('booking.timezone'))->format('H:i'),
            // The latest end, for example "12:30": the end of the slot computed by FreeSlots (the
            // longest duration plus the options, BookingDuration), the same instant CreateBooking
            // saves as ends_at, written with LocalTime like the booking's page and the emails
            // (local_end, timeRange). The form shows it before the booking is sent (#53).
            'end_time' => LocalTime::fromUtc($this->end)->format('H:i'),
        ];
    }
}
