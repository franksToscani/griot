<?php

namespace App\Http\Resources;

use App\Models\Provider;
use App\Models\ProviderPhoto;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A provider in "Professionisti in evidenza" on the home page (issue #144): only what its card
 * shows. The cover is decorative (the card has its name), so no alternative text; its sizes are
 * only the ones that exist, as in ProviderPhotoPublicResource. It expects the active services
 * with their category and the relation "cover" (App\Home\FeaturedProviders).
 *
 * @mixin Provider
 */
class FeaturedProviderResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var ProviderPhoto|null $cover */
        $cover = $this->resource->getRelation('cover');

        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'name' => $this->name,
            'city' => $this->city,
            // As in the list (ProviderResource): from the active services, without duplicates.
            'categories' => CategoryResource::collection(
                $this->services
                    ->where('is_active', true)
                    ->pluck('category')
                    ->unique('id')
                    ->sortBy('name')
                    ->values(),
            )->resolve($request),
            'cover' => $cover === null ? null : (new ProviderCoverResource($cover))->resolve($request),
        ];
    }
}
