<?php

namespace App\Http\Controllers;

use App\Enums\Weekday;
use App\Http\Resources\ProviderAvailabilityResource;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The weekly time slots of the logged-in provider, with the form to add one.
 */
class ProviderAvailabilityIndexController extends Controller
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

        // Always true today, since the provider is the user's own: defence in depth, as on the
        // services page.
        Gate::authorize('update', $provider);

        $availabilities = $provider->availabilities()->orderBy('weekday')->orderBy('start_time')->get();

        return Inertia::render('provider/availability/index', [
            // resolve() gives plain lists, without a "data" wrapper.
            'availabilities' => ProviderAvailabilityResource::collection($availabilities)->resolve($request),
            // The seven days from Monday, with the key of the translated name (for example "monday").
            'weekdays' => array_map(fn (Weekday $weekday) => [
                'weekday' => $weekday->value,
                'day' => strtolower($weekday->name),
            ], Weekday::cases()),
        ]);
    }
}
