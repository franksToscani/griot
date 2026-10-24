<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreOptionRequest;
use App\Models\Service;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * Adds an option to one of the logged-in provider's services.
 */
class ProviderOptionStoreController extends Controller
{
    /**
     * Handle the incoming request. The service was found among the user's own by the request (404
     * otherwise); the option is created through its relation: service_id never comes from the input.
     */
    public function __invoke(StoreOptionRequest $request): RedirectResponse
    {
        $service = $request->ownService();

        if ($service === null) {
            return to_route('provider.setup');
        }

        Gate::authorize('update', $service);

        DB::transaction(function () use ($request, $service): void {
            // This MUST be the first statement: two requests adding an option to the same service
            // wait for each other here, so the second one counts the option of the first. The
            // request already checked the limit, before the transaction; this is the real check.
            $locked = Service::query()->whereKey($service->id)->lockForUpdate()->firstOrFail();

            if ($locked->options()->count() >= StoreOptionRequest::MAX_OPTIONS) {
                throw ValidationException::withMessages([
                    'name' => __('provider.options.validation.limit_reached', ['max' => StoreOptionRequest::MAX_OPTIONS]),
                ])->redirectTo(route('provider.services.options.index', $service->id));
            }

            $locked->options()->create($request->optionData());
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => __('provider.options.created')]);

        return to_route('provider.services.options.index', $service->id);
    }
}
