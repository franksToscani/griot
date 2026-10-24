<?php

namespace App\Policies;

use App\Models\Availability;
use App\Models\User;

/**
 * Who can act on a weekly time slot. Found by Laravel from its name (App\Models\Availability →
 * App\Policies\AvailabilityPolicy), without registering it. Creating a slot is authorized on the
 * provider, with ProviderPolicy::update.
 */
class AvailabilityPolicy
{
    /**
     * Only the provider that owns the slot can edit it.
     */
    public function update(User $user, Availability $availability): bool
    {
        return $this->ownedBy($user, $availability);
    }

    /**
     * Only the provider that owns the slot can delete it.
     */
    public function delete(User $user, Availability $availability): bool
    {
        return $this->ownedBy($user, $availability);
    }

    /**
     * The slot's provider_id against the id of the user's provider: the slot's own column, so its
     * provider is never loaded. In the controllers $user->provider is already loaded, so no query
     * runs; called on its own, it is one query the first time. A user without a provider owns
     * nothing. Integers from the database, see ProviderPolicy.
     */
    private function ownedBy(User $user, Availability $availability): bool
    {
        $providerId = $user->provider?->id;

        return $providerId !== null && $availability->provider_id === $providerId;
    }
}
