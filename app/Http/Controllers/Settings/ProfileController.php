<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\ProfileDeleteRequest;
use App\Http\Requests\Settings\ProfileUpdateRequest;
use App\Models\ProviderPhoto;
use App\Profile\DeleteProviderPhoto;
use App\Support\MediaDisk;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class ProfileController extends Controller
{
    /**
     * Show the user's profile settings page.
     */
    public function edit(Request $request): Response
    {
        return Inertia::render('settings/profile', [
            'mustVerifyEmail' => $request->user() instanceof MustVerifyEmail,
            'status' => $request->session()->get('status'),
        ]);
    }

    /**
     * Update the user's profile information.
     */
    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        $request->user()->fill($request->validated());

        if ($request->user()->isDirty('email')) {
            $request->user()->email_verified_at = null;
        }

        $request->user()->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('settings.profile.updated')]);

        return to_route('profile.edit');
    }

    /**
     * Delete the user's profile.
     */
    public function destroy(ProfileDeleteRequest $request): RedirectResponse
    {
        $user = $request->user();
        // Read before: the provider's row and its gallery go with the user (cascade in the
        // database), the files stay on the disk until removed below.
        $photo = $user->provider?->avatar_path;
        $gallery = $user->provider?->photos->flatMap(fn (ProviderPhoto $galleryPhoto) => $galleryPhoto->paths())->all() ?? [];

        Auth::logout();

        $user->delete();

        // Only now, with the account gone: a deletion refused by the database (a provider with
        // bookings, issue #133) throws above and leaves the photo where it is.
        // A failure does not undo the deletion of the account nor reach the user: a warning in
        // the log, with no personal data.
        if ($photo !== null && $photo !== '') {
            MediaDisk::deleteQuietly($photo, 'The photo of a deleted provider account could not be removed from the media disk.');
        }

        foreach ($gallery as $path) {
            DeleteProviderPhoto::removeFile($path);
        }

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/');
    }
}
