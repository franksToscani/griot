<?php

namespace App\Http\Resources;

use App\Booking\LocalTime;
use App\Models\Booking;
use App\Models\ServiceOption;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A booking in the provider's own list. It expects service and options to be already loaded. It
 * leaves out on purpose the public_token and every internal id but the booking's. The customer's
 * fields are data written by the customer: the page shows them as plain text.
 *
 * @mixin Booking
 */
class ProviderBookingResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $start = LocalTime::fromUtc($this->starts_at);
        $end = LocalTime::fromUtc($this->ends_at);
        $options = $this->options->sortBy('id')->values();

        return [
            'id' => $this->id,
            'status' => $this->status->value,
            'local_date' => $start->format('Y-m-d'),
            'local_start' => $start->format('H:i'),
            'local_end' => $end->format('H:i'),
            'customer_name' => $this->customer_name,
            'customer_phone' => $this->customer_phone,
            'customer_email' => $this->customer_email,
            'notes' => $this->notes,
            'service' => [
                'name' => $this->service->name,
            ],
            'options' => $options
                ->map(fn (ServiceOption $option) => [
                    'name' => $option->name,
                    // Decimal string such as "7.50".
                    'extra_price' => $option->extra_price,
                ])
                ->all(),
            // The CURRENT prices of the service and of the options chosen: the booking does not
            // keep the prices of the day it was made.
            'price_cents' => $options->reduce(
                fn (int $total, ServiceOption $option) => $total + self::cents($option->extra_price),
                self::cents($this->service->price),
            ),
        ];
    }

    /**
     * "7.50" becomes 750, with integers only: adding decimals as floating point numbers can give
     * results such as 0.1 + 0.2 = 0.30000000000000004.
     */
    private static function cents(string $price): int
    {
        [$units, $decimals] = array_pad(explode('.', $price, 2), 2, '');

        return (int) $units * 100 + (int) str_pad(substr($decimals, 0, 2), 2, '0');
    }
}
