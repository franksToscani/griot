<?php

namespace App\Profile;

use App\Models\Provider;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Saves the profile fields and the slug of a provider. It never touches is_published,
 * avatar_path or user_id, and the name never changes the slug by itself.
 *
 * One name for a provider, the business one: the account's name (users.name) follows it, right
 * after the save (no transaction here before, none added). Never the other way round.
 */
final class UpdateProviderProfile
{
    /**
     * @throws SlugAlreadyTaken when another provider took the slug in the meantime
     */
    public function update(Provider $provider, ProfileDetails $details, string $slug): Provider
    {
        $provider->fill([
            'name' => $details->name,
            'city' => $details->city,
            'bio' => $details->bio,
            'whatsapp' => $details->whatsapp,
            'instagram' => $details->instagram,
        ]);
        // Not fillable: assigned on its own. Already checked as unique by the request.
        $provider->slug = $slug;

        try {
            $provider->save();
        } catch (UniqueConstraintViolationException) {
            // The only unique column that can change here is the slug.
            throw SlugAlreadyTaken::for($provider);
        }

        // Only once the profile is saved: one name, the business one (SyncAccountName).
        SyncAccountName::from($provider);

        return $provider;
    }
}
