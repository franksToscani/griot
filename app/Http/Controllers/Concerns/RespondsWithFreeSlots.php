<?php

namespace App\Http\Controllers\Concerns;

use App\Booking\FreeSlots;
use App\Booking\InvalidBookingOptions;
use App\Booking\SlotRules;
use App\Http\Requests\ProviderSlotsRequest;
use App\Http\Resources\SlotResource;
use App\Models\Provider;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

/**
 * The free slots of a provider's service on a local day, as JSON: shared by the public route
 * (customers) and the provider's own route, so the two give the same shape and the same errors.
 */
trait RespondsWithFreeSlots
{
    private function freeSlotsResponse(ProviderSlotsRequest $request, Provider $provider, FreeSlots $freeSlots, SlotRules $rules): JsonResponse
    {
        $day = $request->day();

        if (! $freeSlots->isWithinWindow($day)) {
            throw ValidationException::withMessages([
                'date' => __('booking.slots.date_outside_window', ['days' => config()->integer('booking.max_days_ahead')]),
            ]);
        }

        // Only among this provider's services: a missing id and an id of another provider get the
        // same message, so the response does not tell whether that id exists elsewhere.
        $service = $provider->services()->find($request->serviceId());

        if ($service === null) {
            throw ValidationException::withMessages(['service' => __('booking.slots.service_invalid')]);
        }

        try {
            $slots = $freeSlots->slotsFor($provider, $service, $request->optionIds(), $day, $rules);
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
