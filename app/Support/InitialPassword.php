<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * The random initial password of a provider's account, made by "php artisan provider:create"
 * (docs/production.md): 20 characters of Str::password(), letters, digits and symbols, among
 * them "<", ">" and "\". Taken from the container, so that a test can give a fixed one.
 */
class InitialPassword
{
    /**
     * The length of the password.
     */
    public const LENGTH = 20;

    /**
     * A new random password.
     */
    public function generate(): string
    {
        return Str::password(self::LENGTH);
    }
}
