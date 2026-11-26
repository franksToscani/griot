<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\ProfileDeleteRequest;
use App\Http\Requests\Settings\ProfileUpdateRequest;
use App\Profile\AccountHasBookings;
use App\Profile\DeleteProviderAccount;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
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
            // A provider with bookings cannot delete the account (issue #133): the page explains
            // it instead of asking the password.
            'has_bookings' => DeleteProviderAccount::hasBookings($request->user()),
            // One name for a provider, the business one: shown here with a link to the profile,
            // never edited here. Null without a provider, who keeps the field "Nome". The relation
            // was just loaded by hasBookings(): no query of its own.
            'provider_name' => $request->user()->provider?->name,
        ]);
    }

    /**
     * Update the user's profile information. For a provider only the email: the name is the
     * business one, changed in the profile (ProfileUpdateRequest leaves it out of the data).
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
     * Delete the user's account. A provider with bookings is refused by ProfileDeleteRequest, and
     * again by DeleteProviderAccount if a booking arrives meanwhile (issue #133): the provider
     * stays logged in, back on this page with the error "account".
     */
    public function destroy(ProfileDeleteRequest $request, DeleteProviderAccount $deleteAccount): RedirectResponse
    {
        try {
            $deleteAccount->delete($request->user());
        } catch (AccountHasBookings) {
            throw ValidationException::withMessages(['account' => DeleteProviderAccount::refusalMessage()])
                ->redirectTo(route('profile.edit'));
        }

        // Only after a deletion that succeeded. logoutCurrentDevice(), not logout(): logout()
        // writes a new "remember me" token on the user, and save() on a deleted model inserts
        // it again.
        Auth::logoutCurrentDevice();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/');
    }
}
