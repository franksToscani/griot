<?php

namespace App\Http\Resources;

use App\Models\ProviderPhoto;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A photo of the provider's work, for the gallery card of the edit page (issue #143): only what
 * the card shows. Every size that exists, as width and address for srcset, as on the public page:
 * the smallest alone was enlarged two or three times on a phone (#53). Width and height keep the
 * page from moving while it loads. No name, path or consent_at.
 *
 * @mixin ProviderPhoto
 */
class ProviderPhotoEditResource extends JsonResource
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
            'variants' => array_map(fn (int $side) => [
                'width' => $this->widthFor($side),
                'url' => $this->urlFor($side),
            ], $this->sizes()),
            'width' => $this->width,
            'height' => $this->height,
            'alt_text' => $this->alt_text,
            'position' => $this->position,
        ];
    }
}
