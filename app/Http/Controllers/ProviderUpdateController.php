<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateProviderRequest;
use App\Profile\SlugAlreadyTaken;
use App\Profile\UpdateProviderProfile;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * Saves the logged-in provider's profile from the edit page.
 */
class ProviderUpdateController extends Controller
{
    /**
     * Handle the incoming request. The provider is always $request->user()->provider: id, slug of
     * another provider or user_id in the body have no effect.
     */
    public function __invoke(UpdateProviderRequest $request, UpdateProviderProfile $updateProfile): RedirectResponse
    {
        $provider = $request->user()?->provider;

        if ($provider === null) {
            return to_route('provider.setup');
        }

        // Always true today, since the provider is the user's own: defence in depth, so that the
        // check is already there if one day the route receives an identifier.
        Gate::authorize('update', $provider);

        try {
            $updateProfile->update($provider, $request->details(), $request->slug());
        } catch (SlugAlreadyTaken) {
            // Taken by another provider between the validation and the save.
            throw ValidationException::withMessages(['slug' => __('provider.validation.slug_taken')]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('provider.edit.updated')]);

        return to_route('provider.edit');
    }
}
