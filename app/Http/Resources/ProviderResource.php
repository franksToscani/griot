<?php

namespace App\Http\Resources;

use App\Models\Provider;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

/**
 * A provider as shown in the public list.
 *
 * @mixin Provider
 */
class ProviderResource extends JsonResource
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
            'city' => $this->city,
            'avatar_url' => $this->avatar_path !== null
                ? Storage::disk('public')->url($this->avatar_path)
                : null,
            // A provider has no category of its own: it comes from its active services, without duplicates.
            'categories' => $this->whenLoaded('services', fn () => CategoryResource::collection(
                $this->services
                    ->where('is_active', true)
                    ->pluck('category')
                    ->unique('id')
                    ->sortBy('name')
                    ->values(),
            )),
        ];
    }
}
