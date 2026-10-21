<?php

namespace App\Http\Controllers;

use App\Profile\CannotDeleteService;
use App\Profile\ManageService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * Deletes one of the logged-in provider's services, only if it has no bookings.
 */
class ProviderServiceDestroyController extends Controller
{
    /**
     * Handle the incoming request. The service is searched only among the user's own (404 for any
     * other id). Whether it can be deleted is decided by ManageService.
     */
    public function __invoke(Request $request, ManageService $manageService): RedirectResponse
    {
        $provider = $request->user()?->provider;

        if ($provider === null) {
            return to_route('provider.setup');
        }

        $service = $provider->services()->whereKey($request->route('service'))->firstOrFail();
        Gate::authorize('delete', $service);

        try {
            $manageService->delete($service);
        } catch (CannotDeleteService) {
            // Back to the edit page, which suggests to deactivate it. redirectTo(): the Referer is
            // not reliable.
            throw ValidationException::withMessages(['service' => __('provider.services.cannot_delete')])
                ->redirectTo(route('provider.services.edit', $service->id));
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('provider.services.deleted')]);

        return to_route('provider.services.index');
    }
}
