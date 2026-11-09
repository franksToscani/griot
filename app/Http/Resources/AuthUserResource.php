<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The logged-in user as every page receives it (shared prop "auth.user", issue #86): only what the
 * pages show, chosen one by one. Never the model itself: a relation loaded on it by a controller
 * (the provider, one day the bookings with the customers' names) would end up in every page.
 *
 * - name: the user menu and the profile settings;
 * - email: the user menu and the profile settings;
 * - email_verified_at: the profile settings, which offer to send the link again when it is null.
 *
 * Not the id, the dates, the password, the remember token or the two-factor secrets.
 *
 * @mixin User
 */
class AuthUserResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array{name: string, email: string, email_verified_at: string|null}
     */
    public function toArray(Request $request): array
    {
        return [
            'name' => $this->name,
            'email' => $this->email,
            'email_verified_at' => $this->email_verified_at?->toJSON(),
        ];
    }
}
