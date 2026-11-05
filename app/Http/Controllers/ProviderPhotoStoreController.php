<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreProviderPhotoRequest;
use App\Profile\GalleryFull;
use App\Profile\InvalidAvatar;
use App\Profile\PhotoNotStored;
use App\Profile\StoreProviderPhoto;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * Adds a photo to the logged-in provider's gallery (issue #143). The form will live on the edit
 * page, as the provider's photo.
 */
class ProviderPhotoStoreController extends Controller
{
    /**
     * Handle the incoming request. The provider is always $request->user()->provider.
     */
    public function __invoke(StoreProviderPhotoRequest $request, StoreProviderPhoto $storePhoto): RedirectResponse
    {
        $provider = $request->user()?->provider;

        if ($provider === null) {
            return to_route('provider.setup');
        }

        Gate::authorize('update', $provider);

        try {
            $storePhoto->store($provider, $request->photo(), $request->altText());
        } catch (InvalidAvatar) {
            // It passed the rules but GD cannot read it, for example a truncated image.
            throw ValidationException::withMessages(['photo' => __('provider.validation.photo_unreadable')])
                ->redirectTo(route('provider.edit'));
        } catch (GalleryFull) {
            throw ValidationException::withMessages(['photo' => __('provider.validation.gallery_full', ['max' => config()->integer('booking.gallery.max_photos')])])
                ->redirectTo(route('provider.edit'));
        } catch (PhotoNotStored $exception) {
            // A problem of the disk, not of the photo: reported, and the provider can try again.
            report($exception);

            throw ValidationException::withMessages(['photo' => __('provider.validation.photo_not_saved')])
                ->redirectTo(route('provider.edit'));
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('provider.photos.saved')]);

        return to_route('provider.edit');
    }
}
