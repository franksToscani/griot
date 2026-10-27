<?php

namespace App\Policies;

use App\Models\Booking;
use App\Models\User;

/**
 * Who can act on a booking as its provider. Found by Laravel from its name (App\Models\Booking →
 * App\Policies\BookingPolicy), without registering it. The customer acts by the private link, not
 * through a user: CancelBooking.
 */
class BookingPolicy
{
    /**
     * Only the provider of the booking can confirm, reject or cancel it. WHETHER the change is
     * allowed (status, start) is decided by BookingTransitions, not here.
     */
    public function manage(User $user, Booking $booking): bool
    {
        // The booking's own provider_id column, so its provider is never loaded. In the controllers
        // $user->provider is already loaded, so no query runs; called on its own, it is one query
        // the first time. A user without a provider owns nothing. Integers from the database, see
        // ProviderPolicy.
        $providerId = $user->provider?->id;

        return $providerId !== null && $booking->provider_id === $providerId;
    }
}
