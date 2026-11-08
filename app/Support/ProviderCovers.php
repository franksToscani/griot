<?php

namespace App\Support;

use App\Models\Provider;
use App\Models\ProviderPhoto;
use Illuminate\Support\Collection;

/**
 * The covers of a group of providers (the home's featured providers, the public list): the
 * providers come with cover_photo_id (scope Provider::withCoverPhotoId()), and one query loads
 * only those photos, never every photo of every provider. Each provider gets the relation
 * "cover": its first photo, or null.
 *
 * The query runs even when no provider has a photo (an empty "where in"), so the number of
 * queries of a page never depends on the data.
 */
final class ProviderCovers
{
    /**
     * @param  Collection<int, Provider>  $providers
     */
    public static function attach(Collection $providers): void
    {
        $covers = ProviderPhoto::query()
            ->whereIn('id', $providers->pluck('cover_photo_id')->filter()->all())
            ->get()
            ->keyBy('id');

        foreach ($providers as $provider) {
            $provider->setRelation('cover', $covers->get($provider->getAttribute('cover_photo_id')));
        }
    }
}
