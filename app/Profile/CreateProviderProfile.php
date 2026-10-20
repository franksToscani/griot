<?php

namespace App\Profile;

use App\Models\Provider;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use RuntimeException;

/**
 * Creates the provider of a user, not published, with a unique slug derived from its name.
 */
final class CreateProviderProfile
{
    private const MAX_ATTEMPTS = 5;

    /**
     * @throws ProviderAlreadyExists when the user already has a provider
     */
    public function create(User $user, ProfileDetails $details): Provider
    {
        if ($this->hasProvider($user)) {
            throw ProviderAlreadyExists::for($user);
        }

        $base = ProviderSlug::fromName($details->name);

        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            try {
                // Written by hand, never from the input: Provider::$fillable also allows slug,
                // avatar_path and is_published. Through the relation: user_id is set by the code.
                return $user->provider()->create([
                    'slug' => $this->freeSlug($base),
                    'name' => $details->name,
                    'city' => $details->city,
                    'bio' => $details->bio,
                    'whatsapp' => $details->whatsapp,
                    'instagram' => $details->instagram,
                    // Explicit, so that the model in memory already has it (the column default
                    // would only be in the database).
                    'is_published' => false,
                ]);
            } catch (UniqueConstraintViolationException) {
                // Two unique columns: user_id (the same user submitted twice) or slug (another
                // provider took the chosen one in the meantime: read the slugs again and retry).
                if ($this->hasProvider($user)) {
                    throw ProviderAlreadyExists::for($user);
                }
            }
        }

        // Does not happen in normal use: it needs five other providers taking the chosen slug
        // between the read and the insert, five times in a row.
        throw new RuntimeException(sprintf('No free slug found for user %d after %d attempts.', $user->id, self::MAX_ATTEMPTS));
    }

    /**
     * Whether the user has a provider in the database now. Impure: it is asked again after a
     * failed insert, and the answer can change in between.
     *
     * @phpstan-impure
     */
    private function hasProvider(User $user): bool
    {
        return $user->provider()->exists();
    }

    /**
     * The first free slug among base, base-2, base-3... read with one query. Slugs contain only
     * a-z, 0-9 and "-", so the LIKE pattern has no wildcard other than the final "%".
     */
    private function freeSlug(string $base): string
    {
        $taken = array_flip(Provider::query()
            ->where('slug', 'like', $base.'%')
            ->pluck('slug')
            ->map(fn (mixed $slug): string => (string) $slug)
            ->all());

        if (! isset($taken[$base])) {
            return $base;
        }

        $number = 2;

        while (isset($taken["{$base}-{$number}"])) {
            $number++;
        }

        return "{$base}-{$number}";
    }
}
