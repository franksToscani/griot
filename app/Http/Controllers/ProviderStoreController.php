<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreProviderRequest;
use App\Profile\CreateProviderProfile;
use App\Profile\ProviderAlreadyExists;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

/**
 * Creates the logged-in user's provider from the "complete your profile" form.
 */
class ProviderStoreController extends Controller
{
    /**
     * Handle the incoming request. The provider is always created for $request->user(): no id,
     * slug or user_id is read from the address or the body. It starts unpublished.
     */
    public function __invoke(StoreProviderRequest $request, CreateProviderProfile $createProfile): RedirectResponse
    {
        try {
            $createProfile->create($request->user(), $request->details());
        } catch (ProviderAlreadyExists) {
            // A second submit, or a user who already has a provider: back to the dashboard
            // without writing anything and without a message, as ProviderSetupController does.
            return to_route('dashboard');
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('provider.setup.created')]);

        return to_route('dashboard');
    }
}
