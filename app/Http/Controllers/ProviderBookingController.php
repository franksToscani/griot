<?php

namespace App\Http\Controllers;

use App\Booking\CreateBooking;
use App\Booking\InvalidBookingOptions;
use App\Booking\SlotNotAvailable;
use App\Http\Requests\StoreBookingRequest;
use App\Models\Provider;
use App\Notifications\NewBookingNotification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Throwable;

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
        // Honeypot, checked right after the validation: no booking, no notification, and a fake
        // success. A bot with valid data sees a success, one with wrong data gets the normal
        // validation errors, so neither finds out about the trap. The log line has a fixed message
        // and the provider id only, nothing the customer wrote. Risk of false positives: a browser
        // or password manager that fills the hidden field automatically would lose a real request
        // without telling the customer.
        if ($request->honeypotFilled()) {
            Log::info('Booking request discarded: honeypot field filled.', ['provider_id' => $publishedProvider->id]);
            Inertia::flash('toast', ['type' => 'success', 'message' => __('booking.create.created')]);

            return to_route('providers.show', $publishedProvider->slug);
        }

        // Only among this provider's services: a missing id and an id of another provider get the
        // same message, so the response does not tell whether that id exists elsewhere.
        $service = $publishedProvider->services()->find($request->serviceId());

        if ($service === null) {
            throw ValidationException::withMessages(['service' => __('booking.slots.service_invalid')]);
        }

        // ServiceNotOfProvider is not caught: the service comes from this provider, so it would be a bug.
        try {
            $booking = $createBooking->create($publishedProvider, $service, $request->optionIds(), $request->startsAt(), $request->customer());
        } catch (SlotNotAvailable) {
            // Taken in the meantime, or never bookable (past, off the grid, outside the window).
            throw ValidationException::withMessages(['starts_at' => __('booking.create.slot_taken')]);
        } catch (InvalidBookingOptions) {
            throw ValidationException::withMessages(['options' => __('booking.slots.options_invalid')]);
        }

        // Sent here, after create() has returned: its transaction is already committed, so the
        // queued job can never run before the commit, nor for a booking that was rolled back.
        // A failure while queueing the email is reported and nothing more: the booking stays
        // and the customer still gets the private page. A failure while sending, inside the
        // queued job, cannot touch the booking: the job runs later, on its own.
        try {
            $publishedProvider->user->notify(new NewBookingNotification($booking));
        } catch (Throwable $exception) {
            report($exception);
        }

        // On to the booking's private page. The public_token goes in a redirect address in two
        // places, both towards the customer's private page: here and after a cancellation
        // (BookingCancellationController). It is passed as a string and not as the model; it
        // never goes in the flash message, in a log or in an error.
        Inertia::flash('toast', ['type' => 'success', 'message' => __('booking.create.created')]);

        return to_route('bookings.show', ['booking' => $booking->public_token]);
    }
}
