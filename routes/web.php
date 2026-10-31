<?php

use App\Http\Controllers\BookingCancellationController;
use App\Http\Controllers\BookingConfirmationController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\LocaleController;
use App\Http\Controllers\ProviderAvailabilityDestroyController;
use App\Http\Controllers\ProviderAvailabilityIndexController;
use App\Http\Controllers\ProviderAvailabilityStoreController;
use App\Http\Controllers\ProviderAvailabilityUpdateController;
use App\Http\Controllers\ProviderAvatarController;
use App\Http\Controllers\ProviderBookingCancelController;
use App\Http\Controllers\ProviderBookingConfirmController;
use App\Http\Controllers\ProviderBookingController;
use App\Http\Controllers\ProviderBookingCreateController;
use App\Http\Controllers\ProviderBookingIndexController;
use App\Http\Controllers\ProviderBookingRejectController;
use App\Http\Controllers\ProviderBookingSlotsController;
use App\Http\Controllers\ProviderBookingStoreController;
use App\Http\Controllers\ProviderController;
use App\Http\Controllers\ProviderEditController;
use App\Http\Controllers\ProviderOptionActivateController;
use App\Http\Controllers\ProviderOptionDeactivateController;
use App\Http\Controllers\ProviderOptionDestroyController;
use App\Http\Controllers\ProviderOptionIndexController;
use App\Http\Controllers\ProviderOptionStoreController;
use App\Http\Controllers\ProviderOptionUpdateController;
use App\Http\Controllers\ProviderPreviewController;
use App\Http\Controllers\ProviderPublishController;
use App\Http\Controllers\ProviderServiceActivateController;
use App\Http\Controllers\ProviderServiceDeactivateController;
use App\Http\Controllers\ProviderServiceDestroyController;
use App\Http\Controllers\ProviderServiceEditController;
use App\Http\Controllers\ProviderServiceIndexController;
use App\Http\Controllers\ProviderServiceStoreController;
use App\Http\Controllers\ProviderServiceUpdateController;
use App\Http\Controllers\ProviderSetupController;
use App\Http\Controllers\ProviderSlotsController;
use App\Http\Controllers\ProviderStoreController;
use App\Http\Controllers\ProviderUnpublishController;
use App\Http\Controllers\ProviderUpdateController;
use App\Http\Middleware\PrivateBookingPage;
use App\Http\Middleware\PrivateProviderPage;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'welcome')->name('home');

// The language selector, for everyone: it only writes the "locale" cookie and goes back.
Route::post('locale', LocaleController::class)->name('locale.update');

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
    // The provider's own bookings, read only: no identifier in the address, always the user's provider.
    Route::get('provider/bookings', ProviderBookingIndexController::class)->name('provider.bookings.index');
    // A booking the provider writes in the own agenda: its page, the free slots to choose its time
    // and the form. Not to be confused with providers.bookings.store and providers.slots, the public
    // ones. The page has no limiter, like the other pages; slots and form share one, with its own
    // counter (see AppServiceProvider).
    Route::get('provider/bookings/create', ProviderBookingCreateController::class)->name('provider.bookings.create');
    Route::get('provider/bookings/slots', ProviderBookingSlotsController::class)
        ->middleware('throttle:provider-bookings')
        ->name('provider.bookings.slots');
    Route::post('provider/bookings', ProviderBookingStoreController::class)
        ->middleware('throttle:provider-bookings')
        ->name('provider.bookings.store');
    // The provider's answers: a booking only among the provider's own. Same limiter as the edits of
    // services, options and hours; the answer over the limit goes back to the bookings page.
    Route::post('provider/bookings/{booking}/confirm', ProviderBookingConfirmController::class)
        ->whereNumber('booking')
        ->middleware('throttle:provider-services-manage')
        ->name('provider.bookings.confirm');
    Route::post('provider/bookings/{booking}/reject', ProviderBookingRejectController::class)
        ->whereNumber('booking')
        ->middleware('throttle:provider-services-manage')
        ->name('provider.bookings.reject');
    Route::post('provider/bookings/{booking}/cancel', ProviderBookingCancelController::class)
        ->whereNumber('booking')
        ->middleware('throttle:provider-services-manage')
        ->name('provider.bookings.cancel');
    // The provider's own public page, published or not: no slug, always the user's provider.
    Route::get('provider/preview', ProviderPreviewController::class)
        ->middleware(PrivateProviderPage::class)
        ->name('provider.preview');
    Route::post('provider/publish', ProviderPublishController::class)
        ->middleware('throttle:provider-publish')
        ->name('provider.publish');
    Route::post('provider/unpublish', ProviderUnpublishController::class)
        ->middleware('throttle:provider-publish')
        ->name('provider.unpublish');
    // The provider's services: no identifier in the address, the provider is the user's own.
    Route::get('provider/services', ProviderServiceIndexController::class)->name('provider.services.index');
    Route::post('provider/services', ProviderServiceStoreController::class)
        ->middleware('throttle:provider-services')
        ->name('provider.services.store');
    // One service: searched in the controllers only among the user's own services, never with an
    // implicit binding on the whole table, so an id of another provider gets a 404 like a missing one.
    Route::get('provider/services/{service}/edit', ProviderServiceEditController::class)
        ->whereNumber('service')
        ->name('provider.services.edit');
    Route::put('provider/services/{service}', ProviderServiceUpdateController::class)
        ->whereNumber('service')
        ->middleware('throttle:provider-services-manage')
        ->name('provider.services.update');
    Route::post('provider/services/{service}/deactivate', ProviderServiceDeactivateController::class)
        ->whereNumber('service')
        ->middleware('throttle:provider-services-manage')
        ->name('provider.services.deactivate');
    Route::post('provider/services/{service}/activate', ProviderServiceActivateController::class)
        ->whereNumber('service')
        ->middleware('throttle:provider-services-manage')
        ->name('provider.services.activate');
    Route::delete('provider/services/{service}', ProviderServiceDestroyController::class)
        ->whereNumber('service')
        ->middleware('throttle:provider-services-manage')
        ->name('provider.services.destroy');
    // The options of one service: the service only among the user's own, the option only among
    // the options of that service.
    Route::get('provider/services/{service}/options', ProviderOptionIndexController::class)
        ->whereNumber('service')
        ->name('provider.services.options.index');
    Route::post('provider/services/{service}/options', ProviderOptionStoreController::class)
        ->whereNumber('service')
        ->middleware('throttle:provider-services')
        ->name('provider.services.options.store');
    Route::put('provider/services/{service}/options/{option}', ProviderOptionUpdateController::class)
        ->whereNumber(['service', 'option'])
        ->middleware('throttle:provider-services-manage')
        ->name('provider.services.options.update');
    Route::delete('provider/services/{service}/options/{option}', ProviderOptionDestroyController::class)
        ->whereNumber(['service', 'option'])
        ->middleware('throttle:provider-services-manage')
        ->name('provider.services.options.destroy');
    // Hide an option from new customers, and show it again: no fields, same search and limit.
    Route::post('provider/services/{service}/options/{option}/deactivate', ProviderOptionDeactivateController::class)
        ->whereNumber(['service', 'option'])
        ->middleware('throttle:provider-services-manage')
        ->name('provider.services.options.deactivate');
    Route::post('provider/services/{service}/options/{option}/activate', ProviderOptionActivateController::class)
        ->whereNumber(['service', 'option'])
        ->middleware('throttle:provider-services-manage')
        ->name('provider.services.options.activate');
    // The weekly time slots of the provider: a slot only among the provider's own. Same limiters
    // as the services; the answer over the limit goes back to this page.
    Route::get('provider/availability', ProviderAvailabilityIndexController::class)->name('provider.availability.index');
    Route::post('provider/availability', ProviderAvailabilityStoreController::class)
        ->middleware('throttle:provider-services')
        ->name('provider.availability.store');
    Route::put('provider/availability/{availability}', ProviderAvailabilityUpdateController::class)
        ->whereNumber('availability')
        ->middleware('throttle:provider-services-manage')
        ->name('provider.availability.update');
    Route::delete('provider/availability/{availability}', ProviderAvailabilityDestroyController::class)
        ->whereNumber('availability')
        ->middleware('throttle:provider-services-manage')
        ->name('provider.availability.destroy');
});

require __DIR__.'/settings.php';
