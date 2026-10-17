<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProviderIndexRequest;
use App\Http\Resources\CategoryResource;
use App\Http\Resources\ProviderProfileResource;
use App\Http\Resources\ProviderResource;
use App\Models\Provider;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ProviderController extends Controller
{
    /**
     * Show the public list of published providers, optionally filtered by category and city.
     */
    public function index(ProviderIndexRequest $request): Response
    {
        $filters = $request->filters();

        $providers = Provider::query()
            ->published()
            ->when($filters['category'], fn ($query, string $slug) => $query->inCategory($slug))
            ->when($filters['city'], fn ($query, string $city) => $query->inCity($city))
            // Active services and their categories in two extra queries, not one per provider.
            ->with([
                'services' => fn ($query) => $query->where('is_active', true),
                'services.category',
            ])
            ->orderBy('name')
            ->paginate(12)
            // Page links keep the active filters.
            ->appends(array_filter($filters));

        return Inertia::render('providers/index', [
            'providers' => ProviderResource::collection($providers),
            'filters' => $filters,
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
    public function show(Request $request, string $slug): Response
    {
        // Searching among the published ones only: an unpublished provider gets the same 404
        // as a missing one, so the page does not reveal that it exists.
        $provider = Provider::query()
            ->published()
            ->withPublicProfile()
            ->where('slug', $slug)
            ->firstOrFail();

        return Inertia::render('providers/show', [
            // resolve() gives the provider's fields directly, without a "data" wrapper.
            'provider' => (new ProviderProfileResource($provider))->resolve($request),
        ]);
    }
}
