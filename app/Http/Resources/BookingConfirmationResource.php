<?php

namespace App\Http\Resources;

use App\Models\Booking;
use App\Models\ServiceOption;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A booking as the customer sees it from the private link. It expects provider, service and
 * options to be already loaded. It leaves out on purpose the public_token (the page gets the
 * whole private address separately) and the customer's personal data.
 *
 * @mixin Booking
 */
class BookingConfirmationResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        // The provider's local time, the same rule as the slots: the browser's time zone does not matter.
        $timezone = config()->string('booking.timezone');
        $start = $this->starts_at->setTimezone($timezone);
        $end = $this->ends_at->setTimezone($timezone);

        return [
            'status' => $this->status->value,
            'provider' => [
                'name' => $this->provider->name,
                'city' => $this->provider->city,
                'slug' => $this->provider->slug,
            ],
            'service' => [
                'name' => $this->service->name,
                // Decimal string such as "90.00": the page formats it and adds the options.
                'price' => $this->service->price,
            ],
            'options' => array_values($this->options
                ->sortBy('id')
                ->map(fn (ServiceOption $option) => [
                    'name' => $option->name,
                    'extra_price' => $option->extra_price,
                ])
                ->all()),
            'local_date' => $start->format('Y-m-d'),
            'local_start' => $start->format('H:i'),
            'local_end' => $end->format('H:i'),
        ];
    }
}
