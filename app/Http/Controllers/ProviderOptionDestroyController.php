<?php

namespace App\Http\Controllers;

use App\Profile\CannotDeleteOption;
use App\Profile\ManageOption;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * Deletes an option, only if no booking chose it.
 */
class ProviderOptionDestroyController extends Controller
{
    /**
     * Handle the incoming request. The service is searched only among the user's own, the option
     * only among the options of that service: any other id gets the same 404.
     */
    public function __invoke(Request $request, ManageOption $manageOption): RedirectResponse
    {
        $provider = $request->user()?->provider;

        if ($provider === null) {
            return to_route('provider.setup');
        }

        $service = $provider->services()->whereKey($request->route('service'))->firstOrFail();
        $option = $service->options()->whereKey($request->route('option'))->firstOrFail();
        // The option was found through its service: no query for the policy.
        $option->setRelation('service', $service);
        Gate::authorize('delete', $option);

        $page = route('provider.services.options.index', $service->id);

        try {
            $manageOption->delete($option);
        } catch (CannotDeleteOption) {
            // redirectTo(): the Referer is not reliable.
            throw ValidationException::withMessages(['option' => __('provider.options.cannot_delete')])->redirectTo($page);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('provider.options.deleted')]);

        return redirect()->to($page);
    }
}
