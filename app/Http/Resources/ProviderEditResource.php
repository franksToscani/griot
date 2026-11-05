<?php

namespace App\Http\Resources;

use App\Http\Requests\StoreProviderPhotoRequest;
use App\Models\Provider;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The logged-in provider's profile, to fill in the edit form: the editable fields, plus
 * is_published for the help text of the slug, avatar_url for the photo form, and the gallery card's
 * photos (in their order) and limits. No id, user_id or avatar_path.
 *
 * @mixin Provider
 */
class ProviderEditResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'name' => $this->name,
            'city' => $this->city,
            'bio' => $this->bio,
            'whatsapp' => $this->whatsapp,
            'instagram' => $this->instagram,
            'slug' => $this->slug,
            'is_published' => $this->is_published,
            // The current photo for the photo form, or null for the initials.
            'avatar_url' => $this->avatarUrl(),
            // The gallery of the work (issue #143), in the order of the relation: position, then id.
            'photos' => ProviderPhotoEditResource::collection($this->photos)->resolve($request),
            // For the texts of the card: how many photos at most, and the shortest longest side.
            'gallery' => [
                'max_photos' => config()->integer('booking.gallery.max_photos'),
                'min_side' => StoreProviderPhotoRequest::MIN_LONGEST_SIDE,
            ],
        ];
    }
}
