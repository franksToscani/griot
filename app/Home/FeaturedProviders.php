<?php

namespace App\Home;

use App\Models\Provider;
use App\Models\ProviderPhoto;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * The providers of "Professionisti in evidenza" on the home page (issue #144, docs/home.md):
 * published, with at least one photo of their work and at least one active service (the rule of
 * Category::offered(): without one, nothing can be booked), the most recently published first.
 * Each comes with its cover, the first photo by position, as the relation "cover".
 *
 * Four queries however many providers: the providers, their active services, the categories of
 * those, and the covers. The cover's id is picked by a correlated subquery with a limit, which
 * Laravel writes for each database (no window function, nothing of one database only); then only
 * those photos are loaded, never every photo of every provider.
 */
final class FeaturedProviders
{
    /**
     * How many providers at most.
     */
    public const LIMIT = 8;

    /**
     * @return Collection<int, Provider>
     */
    public function get(): Collection
    {
        $providers = Provider::query()
            ->published()
            ->whereHas('photos')
            ->whereHas('services', fn (Builder $services) => $services->where('is_active', true))
            ->addSelect([
                'cover_photo_id' => ProviderPhoto::query()
                    ->select('id')
                    ->whereColumn('provider_id', 'providers.id')
                    ->orderBy('position')
                    ->orderBy('id')
                    ->limit(1),
            ])
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->limit(self::LIMIT)
            ->with([
                // Only the active services, for the categories of the card, as in the list.
                'services' => fn ($services) => $services->where('is_active', true),
                'services.category',
            ])
            ->get();

        $covers = ProviderPhoto::query()
            ->whereIn('id', $providers->pluck('cover_photo_id')->filter()->all())
            ->get()
            ->keyBy('id');

        foreach ($providers as $provider) {
            $provider->setRelation('cover', $covers->get($provider->getAttribute('cover_photo_id')));
        }

        return $providers;
    }
}
