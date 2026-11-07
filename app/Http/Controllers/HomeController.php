<?php

namespace App\Http\Controllers;

use App\Home\FeaturedProviders;
use App\Http\Resources\CategoryResource;
use App\Http\Resources\FeaturedProviderResource;
use App\Models\Category;
use App\Models\Provider;
use App\Support\LinkPreview;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The home page for the customers (issue #144, docs/home.md), for everyone: a logged-in provider
 * sees it too, with "Il mio pannello" in the header. A public page, with the preview of the site.
 */
class HomeController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(Request $request, LinkPreview $preview, FeaturedProviders $featured): Response
    {
        $preview->publicPage();

        // Only the categories with something to book (an active service of a published provider):
        // a link to an empty list would not help. One query; sorted on the name shown, which
        // depends on the language.
        $categories = Category::query()
            ->offered()
            ->get()
            ->sortBy(fn (Category $category) => $category->displayName(), SORT_NATURAL | SORT_FLAG_CASE)
            ->values();

        return Inertia::render('home', [
            // resolve(): a plain list, without the "data" wrapper.
            'categories' => CategoryResource::collection($categories)->resolve($request),
            // "Professionisti in evidenza": at most 8, the most recently published first.
            'featured' => FeaturedProviderResource::collection($featured->get())->resolve($request),
            // The cities of the search in the band at the top: the same list, and the same order,
            // as the city filter of /providers (ProviderIndexRequest), so every one is accepted.
            'cities' => Provider::publishedCities(),
        ]);
    }
}
