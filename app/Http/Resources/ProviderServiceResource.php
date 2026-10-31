<?php

namespace App\Http\Resources;

use App\Models\Service;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A service of the logged-in provider, in its private area: active or not. It expects the
 * category to be already loaded. No provider_id and no dates.
 *
 * @mixin Service
 */
class ProviderServiceResource extends JsonResource
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
            'category' => [
                'id' => $this->category->id,
                'name' => $this->category->displayName(),
            ],
            // Decimal string such as "25.50": the page formats it as euros.
            'price' => $this->price,
            'duration_min' => $this->duration_min,
            'duration_max' => $this->duration_max,
            'is_active' => $this->is_active,
        ];
    }
}
