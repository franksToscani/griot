<?php

namespace App\Http\Resources;

use App\Models\Availability;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One weekly time slot. Times are the provider's local times, shown as stored.
 *
 * @mixin Availability
 */
class AvailabilityResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            // "09:00:00" becomes "09:00": no time zone conversion, only the seconds are dropped.
            'start' => substr($this->start_time, 0, 5),
            'end' => substr($this->end_time, 0, 5),
        ];
    }
}
