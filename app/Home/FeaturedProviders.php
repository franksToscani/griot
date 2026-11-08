<?php

namespace App\Home;

use App\Models\Provider;
use App\Support\ProviderCovers;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * The providers of "Professionisti in evidenza" on the home page (issue #144, docs/home.md):
 * published, with at least one photo of their work and at least one active service (the rule of
 * Category::offered(): without one, nothing can be booked), the most recently published first.
 * Each comes with its cover, the first photo by position, as the relation "cover".
 *
 * Four queries however many providers: the providers, their active services, the categories of
 * those, and the covers. The cover is the one of the public list too: the scope
 * Provider::withCoverPhotoId() and App\Support\ProviderCovers.
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
            ->withCoverPhotoId()
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->limit(self::LIMIT)
            ->with([
                // Only the active services, for the categories of the card, as in the list.
                'services' => fn ($services) => $services->where('is_active', true),
                'services.category',
            ])
            ->get();

        ProviderCovers::attach($providers);

        return $providers;
    }
}
