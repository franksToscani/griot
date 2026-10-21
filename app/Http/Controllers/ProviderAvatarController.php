<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreProviderAvatarRequest;
use App\Profile\InvalidAvatar;
use App\Profile\StoreProviderAvatar;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * Saves the logged-in provider's photo, from its own form on the edit page.
 */
class ProviderAvatarController extends Controller
{
    /**
     * Handle the incoming request. The provider is always $request->user()->provider.
     */
    public function __invoke(StoreProviderAvatarRequest $request, StoreProviderAvatar $storeAvatar): RedirectResponse
    {
        $provider = $request->user()?->provider;

        if ($provider === null) {
            return to_route('provider.setup');
        }

        // Always true today, since the provider is the user's own: defence in depth, so that the
        // check is already there if one day the route receives an identifier.
        Gate::authorize('update', $provider);

        try {
            $storeAvatar->store($provider, $request->avatar());
        } catch (InvalidAvatar) {
            // It passed the rules but GD cannot read it, for example a truncated image.
            throw ValidationException::withMessages(['avatar' => __('provider.validation.avatar_unreadable')]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('provider.avatar.saved')]);

        return to_route('provider.edit');
    }
}
