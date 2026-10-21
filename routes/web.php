<?php

use App\Http\Controllers\BookingCancellationController;
use App\Http\Controllers\BookingConfirmationController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ProviderAvatarController;
use App\Http\Controllers\ProviderBookingController;
use App\Http\Controllers\ProviderController;
use App\Http\Controllers\ProviderEditController;
use App\Http\Controllers\ProviderPublishController;
use App\Http\Controllers\ProviderSetupController;
use App\Http\Controllers\ProviderSlotsController;
use App\Http\Controllers\ProviderStoreController;
use App\Http\Controllers\ProviderUnpublishController;
use App\Http\Controllers\ProviderUpdateController;
use App\Http\Middleware\PrivateBookingPage;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'welcome')->name('home');

Route::get('providers', [ProviderController::class, 'index'])->name('providers.index');
Route::get('providers/{slug}', [ProviderController::class, 'show'])->name('providers.show');
// ":slug" is only for Wayfinder, so that it generates a type with the slug: Laravel uses the
// Route::bind of "publishedProvider" (AppServiceProvider) and ignores it. The 404 tests on
// unpublished and missing providers in ProviderSlotsTest protect this behaviour.
Route::get('providers/{publishedProvider:slug}/slots', ProviderSlotsController::class)
    ->middleware('throttle:booking-slots')
    ->name('providers.slots');
// Same binding and same ":slug" note as the slots route above.
Route::post('providers/{publishedProvider:slug}/bookings', ProviderBookingController::class)
    ->middleware('throttle:booking-store')
    ->name('providers.bookings.store');

// Implicit binding by public_token. The pattern gives a 404 to a code of the wrong shape (an id
// too) without querying the database; an unknown code gets the same 404 from the binding.
Route::get('bookings/{booking:public_token}', BookingConfirmationController::class)
    ->where('booking', '[A-Za-z0-9]{48}')
    ->middleware(PrivateBookingPage::class)
    ->name('bookings.show');
// POST only, in the web group with CSRF protection: a GET (a link, a prefetch) cannot cancel.
// Same pattern, binding and headers as bookings.show.
// Its "throttle:" limit, like the two above, is defined in AppServiceProvider::configureRateLimiting.
Route::post('bookings/{booking:public_token}/cancel', BookingCancellationController::class)
    ->where('booking', '[A-Za-z0-9]{48}')
    ->middleware([PrivateBookingPage::class, 'throttle:booking-cancel'])
    ->name('bookings.cancel');

// The provider's private area. No route has an id or a slug: the provider is always the
// logged-in user's own ($request->user()->provider).
Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('dashboard', DashboardController::class)->name('dashboard');
    Route::get('provider/setup', ProviderSetupController::class)->name('provider.setup');
    Route::post('provider/setup', ProviderStoreController::class)->name('provider.store');
    Route::get('provider/profile', ProviderEditController::class)->name('provider.edit');
    Route::put('provider/profile', ProviderUpdateController::class)->name('provider.update');
    // A separate POST from the profile form. Its "throttle:" limit is in AppServiceProvider.
    Route::post('provider/avatar', ProviderAvatarController::class)
        ->middleware('throttle:provider-avatar')
        ->name('provider.avatar.store');
    // No fields: whatever is sent in the body is ignored. One limiter for both, see AppServiceProvider.
    Route::post('provider/publish', ProviderPublishController::class)
        ->middleware('throttle:provider-publish')
        ->name('provider.publish');
    Route::post('provider/unpublish', ProviderUnpublishController::class)
        ->middleware('throttle:provider-publish')
        ->name('provider.unpublish');
});

require __DIR__.'/settings.php';
