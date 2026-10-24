<?php

namespace App\Http\Controllers;

use App\Http\Resources\ProviderOptionResource;
use App\Http\Resources\ProviderServiceResource;
use App\Models\ServiceOption;
use App\Profile\ManageOption;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The options of one of the logged-in provider's services, with the form to add one.
 */
class ProviderOptionIndexController extends Controller
{
    /**
     * Handle the incoming request. The service is searched only among the user's own: any other
     * id gets the same 404. The policy stays as a second guard.
     */
    public function __invoke(Request $request, ManageOption $manageOption): Response|RedirectResponse
    {
        $provider = $request->user()?->provider;

        if ($provider === null) {
            return to_route('provider.setup');
        }

        $service = $provider->services()->with('category')->whereKey($request->route('service'))->firstOrFail();
        Gate::authorize('update', $service);

        $options = $service->options()->orderBy('id')->get();
        // One query for all the options, with the same rule as the deletion.
        $booked = $manageOption->bookedIds(array_values($options->modelKeys()));

        return Inertia::render('provider/services/options', [
            // resolve() gives plain values, without a "data" wrapper.
            'service' => (new ProviderServiceResource($service))->resolve($request),
            'options' => $options
                ->map(fn (ServiceOption $option) => (new ProviderOptionResource($option, in_array($option->id, $booked, true)))->resolve($request))
                ->values()
                ->all(),
        ]);
    }
}
