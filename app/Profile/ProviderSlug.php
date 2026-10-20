<?php

namespace App\Profile;

use Illuminate\Support\Str;

/**
 * The base slug of a provider, from its name. A pure function, without the database: making it
 * unique is CreateProviderProfile's job.
 */
final class ProviderSlug
{
    /**
     * At most 100 characters: the column holds 120, which leaves room for a "-N" suffix.
     */
    private const MAX_LENGTH = 100;

    public static function fromName(string $name): string
    {
        // Str::slug() transliterates accents ("è" becomes "e") and keeps only a-z, 0-9 and "-".
        $slug = rtrim(Str::substr(Str::slug($name), 0, self::MAX_LENGTH), '-');

        // A name made only of symbols or emoji gives an empty slug.
        return $slug === '' ? 'provider' : $slug;
    }
}
