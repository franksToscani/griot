<?php

namespace App\Http\Controllers;

use App\Booking\FreeSlots;
use App\Booking\InvalidBookingOptions;
use App\Http\Requests\ProviderSlotsRequest;
use App\Http\Resources\SlotResource;
use App\Models\Provider;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

/**
 * The bookable start times of a published provider's service on a local day, as JSON.
 *
 * The client must send "Accept: application/json": validation errors then come back as a
 * 422 JSON response instead of a redirect.
 */
class ProviderSlotsController extends Controller
{
    /**
     * Handle the incoming request. The provider is already resolved, among the published ones,
     * before the request is validated (see AppServiceProvider::configureRouteBindings).
     */
    public function __invoke(ProviderSlotsRequest $request, Provider $publishedProvider, FreeSlots $freeSlots): JsonResponse
    {
        $day = $request->day();

        if (! $freeSlots->isWithinWindow($day)) {
            throw ValidationException::withMessages([
                'date' => __('booking.slots.date_outside_window', ['days' => config()->integer('booking.max_days_ahead')]),
            ]);
        }

        // Only among this provider's services: a missing id and an id of another provider get the
        // same message, so the response does not tell whether that id exists elsewhere.
        $service = $publishedProvider->services()->find($request->serviceId());

        if ($service === null) {
            throw ValidationException::withMessages(['service' => __('booking.slots.service_invalid')]);
        }

        try {
            $slots = $freeSlots->slotsFor($publishedProvider, $service, $request->optionIds(), $day);
        } catch (InvalidBookingOptions) {
            throw ValidationException::withMessages(['options' => __('booking.slots.options_invalid')]);
        }

        return response()->json([
            'date' => $day->toDateString(),
            'timezone' => config()->string('booking.timezone'),
            // resolve() gives a plain list, without a "data" wrapper.
            'slots' => SlotResource::collection($slots)->resolve($request),
        ]);
    }
}
