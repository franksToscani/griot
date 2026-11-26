<?php

namespace App\Profile;

use App\Models\Provider;
use App\Models\User;

/**
 * One name for a provider, the business one (providers.name): the account's name (users.name)
 * follows it. One way only, never the other: the settings page does not change a provider's name
 * (ProfileUpdateRequest), and nothing copies users.name to providers.name.
 *
 * Used by UpdateProviderProfile and CreateProviderProfile, inside their transaction, and by the
 * command providers:sync-account-names for the accounts saved before.
 */
final class SyncAccountName
{
    /**
     * Copies the business name to the account when the two differ (exact comparison). Returns
     * whether the account was changed; false without an account.
     */
    public static function from(Provider $provider, ?User $user = null): bool
    {
        $user ??= $provider->user;

        if ($user === null || $user->name === $provider->name) {
            return false;
        }

        $user->name = $provider->name;
        $user->save();

        return true;
    }

    /**
     * The accounts of many providers (providers:sync-account-names): with $force aligned, without
     * only counted. Each provider whose account has another name gives its slug, or id-N without
     * one, never a name or an email. A provider without an account is skipped and counted apart.
     *
     * @param  iterable<Provider>  $providers  with their "user" relation loaded
     * @return array{different: list<string>, checked: int, updated: int, without: int}
     */
    public static function all(iterable $providers, bool $force): array
    {
        $result = ['different' => [], 'checked' => 0, 'updated' => 0, 'without' => 0];

        foreach ($providers as $provider) {
            $result['checked']++;
            $user = $provider->user;

            if ($user === null) {
                $result['without']++;

                continue;
            }

            // Exact comparison: a space more is a different name.
            if ($user->name === $provider->name) {
                continue;
            }

            $result['different'][] = (string) $provider->slug !== '' ? (string) $provider->slug : 'id-'.$provider->id;

            if ($force && self::from($provider, $user)) {
                $result['updated']++;
            }
        }

        return $result;
    }
}
