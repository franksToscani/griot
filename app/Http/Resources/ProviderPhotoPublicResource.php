<?php

namespace App\Http\Resources;

use App\Models\ProviderPhoto;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A photo of the provider's work, for the mosaic of the public page (issue #143): its size, the
 * provider's own alternative text, and the sizes that really exist, as width and address for
 * srcset. No name, path or consent_at.
 *
 * @mixin ProviderPhoto
 */
class ProviderPhotoPublicResource extends JsonResource
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
            'width' => $this->width,
            'height' => $this->height,
            'alt_text' => $this->alt_text,
            // Smallest first: a photo of 900 px has 400 and 800, never a 1600 that is not there.
            'variants' => array_map(fn (int $side) => [
                'width' => $this->widthFor($side),
                'url' => $this->urlFor($side),
            ], $this->sizes()),
        ];
    }
}
