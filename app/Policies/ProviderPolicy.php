<?php

namespace App\Policies;

use App\Models\Provider;
use App\Models\User;

/**
 * Who can act on a provider. Found by Laravel from its name (App\Models\Provider →
 * App\Policies\ProviderPolicy), without registering it.
 */
class ProviderPolicy
{
    /**
     * Only the owner can edit the provider.
     */
    public function update(User $user, Provider $provider): bool
    {
        // Strict comparison: both come from the database as integers. Laravel opens PDO with
        // ATTR_STRINGIFY_FETCHES and ATTR_EMULATE_PREPARES off (Connectors\Connector), so
        // MySQL and SQLite return integer columns as int; there is no cast on user_id.
        return $provider->user_id === $user->id;
    }
}
