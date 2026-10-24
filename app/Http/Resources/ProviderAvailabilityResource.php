<?php

namespace App\Http\Resources;

use App\Models\Availability;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A weekly time slot of the logged-in provider, for the page that edits it. Times are the
 * provider's local times, shown as stored.
 *
 * @mixin Availability
 */
class ProviderAvailabilityResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            // ISO number, 1 = Monday ... 7 = Sunday.
            'weekday' => $this->weekday->value,
            // "09:00:00" becomes "09:00", the format the form sends back.
            'start_time' => substr($this->start_time, 0, 5),
            'end_time' => substr($this->end_time, 0, 5),
        ];
    }
}
