<?php

use App\Http\Controllers\BookingCancellationController;
use App\Http\Controllers\BookingConfirmationController;
use App\Http\Controllers\ProviderBookingController;
use App\Http\Controllers\ProviderController;
use App\Http\Controllers\ProviderSlotsController;
use App\Http\Middleware\PrivateBookingPage;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'welcome')->name('home');

Route::get('providers', [ProviderController::class, 'index'])->name('providers.index');
Route::get('providers/{slug}', [ProviderController::class, 'show'])->name('providers.show');
// ":slug" is only for Wayfinder, so that it generates a type with the slug: Laravel uses the
// Route::bind of "publishedProvider" (AppServiceProvider) and ignores it. The 404 tests on
// unpublished and missing providers in ProviderSlotsTest protect this behaviour.
Route::get('providers/{publishedProvider:slug}/slots', ProviderSlotsController::class)->name('providers.slots');
// Same binding and same ":slug" note as the slots route above.
Route::post('providers/{publishedProvider:slug}/bookings', ProviderBookingController::class)->name('providers.bookings.store');

// Implicit binding by public_token. The pattern gives a 404 to a code of the wrong shape (an id
// too) without querying the database; an unknown code gets the same 404 from the binding.
Route::get('bookings/{booking:public_token}', BookingConfirmationController::class)
    ->where('booking', '[A-Za-z0-9]{48}')
    ->middleware(PrivateBookingPage::class)
    ->name('bookings.show');
// POST only, in the web group with CSRF protection: a GET (a link, a prefetch) cannot cancel.
// Same pattern, binding and headers as bookings.show.
Route::post('bookings/{booking:public_token}/cancel', BookingCancellationController::class)
    ->where('booking', '[A-Za-z0-9]{48}')
    ->middleware(PrivateBookingPage::class)
    ->name('bookings.cancel');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('dashboard', 'dashboard')->name('dashboard');
});

require __DIR__.'/settings.php';
