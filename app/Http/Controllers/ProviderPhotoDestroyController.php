<?php

namespace App\Http\Controllers;

use App\Profile\DeleteProviderPhoto;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

/**
 * Removes a photo from the logged-in provider's gallery (issue #143). The photo is looked for
 * among the provider's own: a photo of another provider is a 404, as a service or an option.
 */
class ProviderPhotoDestroyController extends Controller
{
    public function __invoke(Request $request, DeleteProviderPhoto $deletePhoto): RedirectResponse
    {
        $provider = $request->user()?->provider;

        if ($provider === null) {
            return to_route('provider.setup');
        }

        $photo = $provider->photos()->whereKey($request->route('photo'))->firstOrFail();
        Gate::authorize('delete', $photo);

        $deletePhoto->delete($photo);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('provider.photos.deleted')]);

        return to_route('provider.edit');
    }
}
