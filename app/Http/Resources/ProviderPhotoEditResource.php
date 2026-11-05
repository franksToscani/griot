<?php

namespace App\Http\Resources;

use App\Models\ProviderPhoto;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A photo of the provider's work, for the gallery card of the edit page (issue #143): only what
 * the card shows. The smallest size is enough for a thumbnail; width and height keep the page
 * from moving while it loads. No name, path or consent_at.
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
            'url' => $this->urlFor($this->sizes()[0]),
            'width' => $this->width,
            'height' => $this->height,
            'alt_text' => $this->alt_text,
            'position' => $this->position,
        ];
    }
}
