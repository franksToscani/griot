<?php

namespace App\Http\Controllers;

use App\Http\Resources\CategoryResource;
use App\Http\Resources\ProviderServiceResource;
use App\Models\Category;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The logged-in provider's services, with the form to add one.
 */
class ProviderServiceIndexController extends Controller
{
    /**
     * Handle the incoming request. The provider is always $request->user()->provider.
     */
    public function __invoke(Request $request): Response|RedirectResponse
    {
        $provider = $request->user()?->provider;

        if ($provider === null) {
            return to_route('provider.setup');
        }

        // Always true today, since the provider is the user's own: defence in depth, so that the
        // check is already there if one day the route receives an identifier.
        Gate::authorize('update', $provider);

        // All of them, active or not, the most recent first (#53: a new service is at the top, next
        // to the form); the categories in one query. Only this list: the public page has its own
        // order (Provider::withPublicProfile(), by name), which does not change.
        $services = $provider->services()->with('category')->orderByDesc('id')->get();

        return Inertia::render('provider/services/index', [
            // resolve() gives plain lists, without a "data" wrapper.
            'services' => ProviderServiceResource::collection($services)->resolve($request),
            // In alphabetical order of the name shown in the page's language (Category::sortName()).
            'categories' => CategoryResource::collection(
                Category::query()->get()->sortBy(fn (Category $c) => $c->sortName())->values(),
            )->resolve($request),
        ]);
    }
}
