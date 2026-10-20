<?php

namespace App\Profile;

use App\Models\User;
use RuntimeException;

/**
 * The user already has a provider: a user can have only one. It happens in normal use, for
 * example with a double submit. The technical message has the user id only, no personal data.
 */
final class ProviderAlreadyExists extends RuntimeException
{
    public static function for(User $user): self
    {
        return new self(sprintf('User %d already has a provider.', $user->id));
    }
}
