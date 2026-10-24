<?php

namespace App\Policies;

use App\Models\ServiceOption;
use App\Models\User;

/**
 * Who can act on an option. Found by Laravel from its name (App\Models\ServiceOption →
 * App\Policies\ServiceOptionPolicy), without registering it. Creating an option is authorized on
 * its service, with ServicePolicy::update.
 */
class ServiceOptionPolicy
{
    /**
     * Only the provider that owns the option's service can edit it.
     */
    public function update(User $user, ServiceOption $option): bool
    {
        return $this->ownedBy($user, $option);
    }

    /**
     * Only the provider that owns the option's service can delete it. Whether it CAN be deleted
     * (bookings) is decided by ManageOption, not here.
     */
    public function delete(User $user, ServiceOption $option): bool
    {
        return $this->ownedBy($user, $option);
    }

    /**
     * The service's provider_id against the id of the user's provider. In the controllers both
     * relations are already loaded (the option is found through its service, which is set on it),
     * so no query runs; called on its own, $option->service and $user->provider are one query
     * each the first time. A user without a provider owns nothing. Integers from the database,
     * see ProviderPolicy.
     */
    private function ownedBy(User $user, ServiceOption $option): bool
    {
        $providerId = $user->provider?->id;

        return $providerId !== null && $option->service?->provider_id === $providerId;
    }
}
