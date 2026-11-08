<?php

namespace App\Http\Controllers;

use App\Booking\FreeSlots;
use App\Http\Requests\ProviderIndexRequest;
use App\Http\Resources\CategoryResource;
use App\Http\Resources\ProviderProfileResource;
use App\Http\Resources\ProviderResource;
use App\Models\Provider;
use App\Support\LinkPreview;
use App\Support\ProviderCovers;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ProviderController extends Controller
{
    /**
     * Show the public list of published providers with something to book (at least one active
     * service, as on the home page), optionally filtered (ProviderIndexRequest::FILTERS: category,
     * city, search by words, maximum price, with photos) and sorted (docs/providers-list.md).
     *
     * Seven queries without a search, however many providers (ProviderListCardsTest): the
     * categories and the cities of the filters, the count of the pagination, the providers (with
     * their lowest price, their number of services and the id of their cover, as subqueries),
     * their active services, the categories of those, the covers.
     */
    public function index(ProviderIndexRequest $request, LinkPreview $preview): Response
    {
        $filters = $request->filters();
        $preview->publicPage(__('providers.list.title'));

        $active = fn (Builder $services) => $services->where('is_active', true);

        $query = Provider::query()
            ->published()
            // Nothing to book, nothing to show (and no price for the card).
            ->whereHas('services', $active)
            ->when($filters['category'], fn ($query, string $slug) => $query->inCategory($slug))
            ->when($filters['city'], fn ($query, string $city) => $query->inCity($city))
            ->when($filters['q'], fn ($query, string $text) => $query->search($text))
            ->when($filters['max_price'], fn ($query, string $price) => $query->withServiceUpTo($price))
            ->when($filters['with_photo'], fn ($query) => $query->withPhotos())
            // The card's "da X €" and "N servizi": the active services only. The lowest price
            // comes as the database gives it (a number in SQLite): the cast makes it "30.00".
            ->withMin(['services as min_price' => $active], 'price')
            ->withCount(['services as services_count' => $active])
            ->withCasts(['min_price' => 'decimal:2'])
            ->withCoverPhotoId()
            // Active services and their categories in two extra queries, not one per provider.
            ->with([
                'services' => fn ($services) => $services->where('is_active', true),
                'services.category',
            ]);

        // The chosen order, then the id: providers with the same name or price keep their place
        // from one page to the next.
        match ($filters['sort']) {
            'recent' => $query->orderByDesc('published_at')->orderByDesc('id'),
            'price_asc' => $query->orderBy('min_price')->orderBy('id'),
            'price_desc' => $query->orderByDesc('min_price')->orderBy('id'),
            default => $query->orderBy('name')->orderBy('id'),
        };

        $providers = $query
            ->paginate(12)
            // Page links keep the active filters and the order.
            ->appends(array_filter($filters));

        // The covers of this page only, in one query.
        ProviderCovers::attach($providers->getCollection());

        return Inertia::render('providers/index', [
            'providers' => ProviderResource::collection($providers),
            'filters' => $filters,
            // The one list of the filters (ProviderIndexRequest), for the page.
            'filterKeys' => [
                'all' => ProviderIndexRequest::FILTERS,
                'panel' => ProviderIndexRequest::PANEL_FILTERS,
            ],
            'filterOptions' => [
                // resolve() gives a plain list: without pagination there is no need for the "data" wrapper.
                'categories' => CategoryResource::collection($request->categoryOptions())->resolve($request),
                'cities' => $request->cityOptions(),
            ],
        ]);
    }

    /**
     * Show the public page of a published provider.
     */
    public function show(Request $request, string $slug, FreeSlots $freeSlots, LinkPreview $preview): Response
    {
        // Searching among the published ones only: an unpublished provider gets the same 404
        // as a missing one, so the page does not reveal that it exists.
        $provider = Provider::query()
            ->published()
            ->withPublicProfile()
            ->where('slug', $slug)
            ->firstOrFail();

        // Only now, with a published provider: a missing or hidden one is a 404 with nothing of it.
        $preview->forProvider($provider);

        return Inertia::render('providers/show', [
            // resolve() gives the provider's fields directly, without a "data" wrapper.
            'provider' => (new ProviderProfileResource($provider))->resolve($request),
            // The days the booking form offers: the same rule the server applies.
            'bookingWindow' => $freeSlots->bookingWindow(),
            // The real page: the owner's preview is ProviderPreviewController.
            'preview' => false,
        ]);
    }
}
