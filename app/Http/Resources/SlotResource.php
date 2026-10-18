<?php

namespace App\Http\Resources;

use App\Booking\TimeWindow;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A bookable slot: its start in UTC, which identifies it, and the same instant as the
 * provider's local time, computed here because the customer's browser time zone is irrelevant.
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
        ];
    }
}
