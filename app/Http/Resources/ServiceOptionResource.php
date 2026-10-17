<?php

namespace App\Http\Resources;

use App\Models\ServiceOption;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ServiceOption
 */
class ServiceOptionResource extends JsonResource
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
            'name' => $this->name,
            // Decimal string such as "20.00": the page formats it as euros.
            'extra_price' => $this->extra_price,
            'extra_duration' => $this->extra_duration,
        ];
    }
}
