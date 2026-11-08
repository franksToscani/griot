<?php

namespace App\Http\Resources;

use App\Models\Provider;
use App\Models\ProviderPhoto;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A provider as shown in the public list: the card (docs/search.md). It expects the active
 * services with their category, min_price and services_count (withMin, withCount) and the
 * relation "cover" (App\Support\ProviderCovers), as ProviderController@index loads them.
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
        /** @var ProviderPhoto|null $cover */
        $cover = $this->resource->relationLoaded('cover') ? $this->resource->getRelation('cover') : null;

        return [
            'id' => $this->id,
            // Used by the card to link to the provider page.
            'slug' => $this->slug,
            'name' => $this->name,
            'city' => $this->city,
            'avatar_url' => $this->avatarUrl(),
            // A provider has no category of its own: it comes from its active services, without duplicates.
            'categories' => $this->whenLoaded('services', fn () => CategoryResource::collection(
                $this->services
                    ->where('is_active', true)
                    ->pluck('category')
                    ->unique('id')
                    ->sortBy('name')
                    ->values(),
            )),
            // The first photo of the work, decorative on the card; null without photos.
            'cover' => $cover === null ? null : (new ProviderCoverResource($cover))->resolve($request),
            // The price of the cheapest active service, as "30.00" (euros, without the options).
            'min_price' => $this->resource->getAttribute('min_price'),
            'services_count' => (int) $this->resource->getAttribute('services_count'),
        ];
    }
}
