<?php

use App\Http\Controllers\ProviderBookingController;
use App\Http\Controllers\ProviderController;
use App\Http\Controllers\ProviderSlotsController;
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

Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('dashboard', 'dashboard')->name('dashboard');
});

require __DIR__.'/settings.php';
