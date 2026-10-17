<?php

namespace App\Http\Resources;

use App\Models\Service;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Service
 */
class ServiceResource extends JsonResource
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
            'description' => $this->description,
            // Decimal string such as "90.00": the page formats it as euros.
            'price' => $this->price,
            'duration_min' => $this->duration_min,
            'duration_max' => $this->duration_max,
            // Resolved to a plain list, so it is not wrapped in "data" inside an already resolved resource.
            'options' => $this->whenLoaded(
                'options',
                fn () => ServiceOptionResource::collection($this->options)->resolve($request),
            ),
        ];
    }
}
