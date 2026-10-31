<?php

namespace App\Http\Controllers;

use App\Booking\FreeSlots;
use App\Booking\SlotRules;
use App\Http\Controllers\Concerns\RespondsWithFreeSlots;
use App\Http\Requests\ProviderSlotsRequest;
use App\Models\Provider;
use Illuminate\Http\JsonResponse;

/**
 * The bookable start times of a published provider's service on a local day, as JSON.
 *
 * The client must send "Accept: application/json": validation errors then come back as a
 * 422 JSON response instead of a redirect.
 */
class ProviderSlotsController extends Controller
{
    use RespondsWithFreeSlots;

    /**
     * Handle the incoming request. The provider is already resolved, among the published ones,
     * before the request is validated (see AppServiceProvider::configureRouteBindings).
     */
    public function __invoke(ProviderSlotsRequest $request, Provider $publishedProvider, FreeSlots $freeSlots): JsonResponse
    {
        return $this->freeSlotsResponse($request, $publishedProvider, $freeSlots, SlotRules::forCustomer());
    }
}
