<?php

namespace App\Http\Resources;

use App\Models\ServiceOption;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An option of one of the logged-in provider's services. "used" (chosen in a booking) is computed
 * by the controller for all the options at once, with ManageOption::bookedIds(), and handed over.
 *
 * @mixin ServiceOption
 */
class ProviderOptionResource extends JsonResource
{
    public function __construct(ServiceOption $option, private readonly bool $used)
    {
        parent::__construct($option);
    }

    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            // Decimal string such as "7.50": the page formats it as euros.
            'extra_price' => $this->extra_price,
            'extra_duration' => $this->extra_duration,
            'used' => $this->used,
        ];
    }
}
