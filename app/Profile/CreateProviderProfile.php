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
            $provider = new Provider([
                'name' => $details->name,
                'city' => $details->city,
                'bio' => $details->bio,
                'whatsapp' => $details->whatsapp,
                'instagram' => $details->instagram,
            ]);
            // Not fillable, so assigned one by one: the slug chosen here, and is_published
            // explicit so that the model in memory already has it (the column default would
            // only be in the database). avatar_path is never set here.
            $provider->slug = $this->freeSlug($base);
            $provider->is_published = false;

            try {
                // Through the relation: user_id is set by the code, never by the input. save()
                // gives false only if a model listener cancels the save; Provider has none.
                $saved = $user->provider()->save($provider);

                if ($saved === false) {
                    throw new RuntimeException(sprintf('The provider of user %d was not saved.', $user->id));
                }

                return $saved;
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
