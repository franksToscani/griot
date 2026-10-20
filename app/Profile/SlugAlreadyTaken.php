<?php

namespace App\Profile;

use App\Models\Provider;
use RuntimeException;

/**
 * The chosen slug was taken by another provider between the validation and the save. It
 * happens in normal use, rarely. The technical message has the provider id only.
 */
final class SlugAlreadyTaken extends RuntimeException
{
    public static function for(Provider $provider): self
    {
        return new self(sprintf('The chosen slug is already taken; provider %d was not updated.', $provider->id));
    }
}
