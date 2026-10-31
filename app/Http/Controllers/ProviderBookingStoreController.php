<?php

namespace App\Http\Controllers;

use App\Booking\CreateBooking;
use App\Booking\InvalidBookingOptions;
use App\Booking\SlotNotAvailable;
use App\Booking\SlotRules;
use App\Enums\BookingStatus;
use App\Http\Requests\StoreProviderBookingRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * The logged-in provider writes a booking in the own agenda, for a customer who asked by phone or
 * in person. Not to be confused with ProviderBookingController, the customer's form on the public
 * page.
 */
class ProviderBookingStoreController extends Controller
{
    /**
     * Handle the incoming request. The provider is always $request->user()->provider and only the
     * validated fields are used: provider_id, status, created_by_provider, ends_at or public_token in
     * the input do nothing.
     *
     * The booking is born confirmed, and no email leaves, neither to the provider, who is writing
     * it, nor to the customer, who already agreed on the time with the provider.
     */
    public function __invoke(StoreProviderBookingRequest $request, CreateBooking $createBooking): RedirectResponse
    {
        $provider = $request->user()?->provider;

        if ($provider === null) {
            return to_route('provider.setup');
        }

        // Always true today, since the provider is the user's own: defence in depth, as on the
        // bookings page.
        Gate::authorize('update', $provider);

        // Only among the provider's own services: a missing id and an id of another provider get
        // the same message, as on the public route.
        $service = $provider->services()->find($request->serviceId());

        if ($service === null) {
            throw ValidationException::withMessages(['service' => __('booking.slots.service_invalid')]);
        }

        // ServiceNotOfProvider is not caught: the service comes from this provider, so it would be a bug.
        try {
            $createBooking->create(
                $provider,
                $service,
                $request->optionIds(),
                $request->startsAt(),
                $request->customer(),
                status: BookingStatus::Confirmed,
                rules: SlotRules::forProvider(),
                createdByProvider: true,
                locale: $request->customerLocale(),
            );
        } catch (SlotNotAvailable) {
            // Taken in the meantime, or never bookable (past, off the grid, outside the window).
            throw ValidationException::withMessages(['starts_at' => __('provider.bookings.create.slot_unavailable')]);
        } catch (InvalidBookingOptions) {
            throw ValidationException::withMessages(['options' => __('booking.slots.options_invalid')]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('provider.bookings.create.created')]);

        return to_route('provider.bookings.index');
    }
}
