<?php

namespace App\Http\Resources;

use App\Models\Provider;
use App\Support\PublicUrl;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The logged-in provider's own profile, as shown in its private area (the dashboard). Only what the
 * page needs: no id, user_id, bio, contacts or avatar_path.
 *
 * @mixin Provider
 */
class ProviderAreaResource extends JsonResource
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
            // Used for the link to the public page, shown only when published.
            'slug' => $this->slug,
            'is_published' => $this->is_published,
            'avatar_url' => $this->avatarUrl(),
            // "Il tuo link" (provider-link-card.tsx): the whole address of the public page, on
            // APP_URL, never on the host of this request (PublicUrl). Always there: unpublished,
            // the card shows it with a warning and no buttons.
            'public_url' => PublicUrl::to(route('providers.show', $this->slug, absolute: false)),
        ];
    }
}
