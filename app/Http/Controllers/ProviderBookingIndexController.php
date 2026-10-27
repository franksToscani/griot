<?php

namespace App\Http\Controllers;

use App\Booking\LocalTime;
use App\Enums\BookingStatus;
use App\Http\Requests\ProviderBookingIndexRequest;
use App\Http\Resources\ProviderBookingResource;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The logged-in provider's bookings, read only. Not to be confused with ProviderBookingController,
 * which creates a booking from the public page.
 */
class ProviderBookingIndexController extends Controller
{
    /**
     * Handle the incoming request. The bookings are read only through the user's own provider.
     *
     * Without filters: the bookings that end from now on, and every pending one, even past, since
     * it still waits for an answer. With a status or a date, only the filters apply.
     */
    public function __invoke(ProviderBookingIndexRequest $request): Response|RedirectResponse
    {
        $provider = $request->user()?->provider;

        if ($provider === null) {
            return to_route('provider.setup');
        }

        // Always true today, since the provider is the user's own: defence in depth, as on the
        // services page.
        Gate::authorize('update', $provider);

        $filters = $request->filters();
        $filtered = array_filter($filters) !== [];

        $bookings = $provider->bookings()
            // One query per relation, however many bookings and options.
            ->with(['service', 'options'])
            ->when($filters['status'], fn ($query, string $status) => $query->where('status', $status))
            // A booking belongs to the local day it starts on.
            ->when($filters['from'], fn ($query, string $from) => $query->where('starts_at', '>=', LocalTime::dayStart($from)))
            ->when($filters['to'], fn ($query, string $to) => $query->where('starts_at', '<', LocalTime::dayEnd($to)))
            ->when(! $filtered, fn ($query) => $query->where(fn ($query) => $query
                ->where('ends_at', '>=', CarbonImmutable::now())
                ->orWhere('status', BookingStatus::Pending)))
            // The pending ones first: they wait for an answer.
            ->orderByRaw('case when status = ? then 0 else 1 end', [BookingStatus::Pending->value])
            ->orderBy('starts_at')
            ->orderBy('id')
            ->paginate(20)
            // Page links keep the active filters.
            ->appends(array_filter($filters));

        return Inertia::render('provider/bookings/index', [
            'bookings' => ProviderBookingResource::collection($bookings),
            'filters' => $filters,
        ]);
    }
}
