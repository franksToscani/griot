<?php

namespace App\Http\Resources;

use App\Models\Provider;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The logged-in provider's profile, to fill in the edit form: the editable fields, plus
 * is_published for the help text of the slug. No id, user_id or avatar_path.
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
        ];
    }
}
