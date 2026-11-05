<?php

namespace App\Policies;

use App\Models\ProviderPhoto;
use App\Models\User;

/**
 * A photo of the gallery belongs to its provider: only that provider's user removes it.
 */
class ProviderPhotoPolicy
{
    public function delete(User $user, ProviderPhoto $photo): bool
    {
        $providerId = $user->provider?->id;

        return $providerId !== null && $photo->provider_id === $providerId;
    }
}
