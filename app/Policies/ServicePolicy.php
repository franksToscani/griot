<?php

namespace App\Policies;

use App\Models\Service;
use App\Models\User;

/**
 * Who can act on a service. Found by Laravel from its name (App\Models\Service →
 * App\Policies\ServicePolicy), without registering it.
 */
class ServicePolicy
{
    /**
     * Only the provider that owns the service can edit it.
     */
    public function update(User $user, Service $service): bool
    {
        return $this->ownedBy($user, $service);
    }

    /**
     * Only the provider that owns the service can delete it. Whether it CAN be deleted (bookings)
     * is decided by ManageService, not here.
     */
    public function delete(User $user, Service $service): bool
    {
        return $this->ownedBy($user, $service);
    }

    /**
     * provider_id against the id of the user's provider: no query for the service's provider.
     * $user->provider is one query the first time, then cached on the user; in the controllers it
     * is already loaded. A user without a provider owns nothing. Both ids are integers from the
     * database, see ProviderPolicy.
     */
    private function ownedBy(User $user, Service $service): bool
    {
        $providerId = $user->provider?->id;

        return $providerId !== null && $service->provider_id === $providerId;
    }
}
