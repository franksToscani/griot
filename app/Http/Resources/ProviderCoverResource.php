<?php

namespace App\Http\Resources;

use App\Models\ProviderPhoto;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The cover of a provider's card (the home's featured providers, the public list): its size and
 * the sizes that exist, as in ProviderPhotoPublicResource. Decorative (the card says the name),
 * so no alternative text.
 *
 * @mixin ProviderPhoto
 */
class ProviderCoverResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'width' => $this->width,
            'height' => $this->height,
            'variants' => array_map(fn (int $side) => [
                'width' => $this->widthFor($side),
                'url' => $this->urlFor($side),
            ], $this->sizes()),
        ];
    }
}
