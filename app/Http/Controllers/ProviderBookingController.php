<?php

namespace App\Http\Controllers;

use App\Booking\CreateBooking;
use App\Booking\InvalidBookingOptions;
use App\Booking\SlotNotAvailable;
use App\Http\Requests\StoreBookingRequest;
use App\Models\Provider;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * Receives the booking form of a published provider's page.
 */
class ProviderBookingController extends Controller
{
    /**
     * Handle the incoming request. The provider is already resolved, among the published ones,
     * before the request is validated (see AppServiceProvider::configureRouteBindings). Only the
     * validated fields are used: provider_id, status, public_token or ends_at in the input do nothing.
     */
    public function __invoke(StoreBookingRequest $request, Provider $publishedProvider, CreateBooking $createBooking): RedirectResponse
    {
        // Only among this provider's services: a missing id and an id of another provider get the
        // same message, so the response does not tell whether that id exists elsewhere.
        $service = $publishedProvider->services()->find($request->serviceId());

        if ($service === null) {
            throw ValidationException::withMessages(['service' => __('booking.slots.service_invalid')]);
        }

        // ServiceNotOfProvider is not caught: the service comes from this provider, so it would be a bug.
        try {
            $createBooking->create($publishedProvider, $service, $request->optionIds(), $request->startsAt(), $request->customer());
        } catch (SlotNotAvailable) {
            // Taken in the meantime, or never bookable (past, off the grid, outside the window).
            throw ValidationException::withMessages(['starts_at' => __('booking.create.slot_taken')]);
        } catch (InvalidBookingOptions) {
            throw ValidationException::withMessages(['options' => __('booking.slots.options_invalid')]);
        }

        // PROVISIONAL: back to the provider page with a message. Issue #29 replaces this with the
        // confirmation page and the private link. The booking's public_token never goes in the
        // redirect, in the flash message or in the page props.
        Inertia::flash('toast', ['type' => 'success', 'message' => __('booking.create.created')]);

        return to_route('providers.show', $publishedProvider->slug);
    }
}
