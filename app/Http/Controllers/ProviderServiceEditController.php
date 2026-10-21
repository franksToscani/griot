<?php

namespace App\Http\Controllers;

use App\Http\Resources\CategoryResource;
use App\Http\Resources\ProviderServiceResource;
use App\Models\Category;
use App\Profile\ManageService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The page where the logged-in provider edits one of its services.
 */
class ProviderServiceEditController extends Controller
{
    /**
     * Handle the incoming request. The service is searched only among the user's own: an id of
     * another provider gets the same 404 as a missing one, so the response does not tell whether
     * that id exists. The policy stays as a second guard.
     */
    public function __invoke(Request $request, ManageService $manageService): Response|RedirectResponse
    {
        $provider = $request->user()?->provider;

        if ($provider === null) {
            return to_route('provider.setup');
        }

        $service = $provider->services()->with('category')->whereKey($request->route('service'))->firstOrFail();
        Gate::authorize('update', $service);

        return Inertia::render('provider/services/edit', [
            // resolve() gives plain values, without a "data" wrapper.
            'service' => (new ProviderServiceResource($service))->resolve($request),
            'categories' => CategoryResource::collection(Category::query()->orderBy('name')->get())->resolve($request),
            // The same rule as the deletion, from ManageService: two exists() queries.
            'bookings' => [
                'has_bookings' => $manageService->blockedBy($service) !== null,
            ],
        ]);
    }
}
