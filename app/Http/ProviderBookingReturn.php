<?php

namespace App\Http;

use Illuminate\Http\Request;

/**
 * Where an answer of the provider to a booking (confirm, reject, cancel) comes back to: the page of
 * that booking when the form was sent from there, the bookings list otherwise. The forms of the
 * booking's page send the hidden field return=detail; any other value, or none, means the list.
 * Never the Referer, which is not reliable: the same address after a success, after a refused
 * change, after a wrong message and over the request limit.
 */
final class ProviderBookingReturn
{
    /**
     * The hidden field of the forms, and its only value.
     */
    public const FIELD = 'return';

    public const DETAIL = 'detail';

    /**
     * The address to go back to. The booking of the address is the raw id of the route (the
     * routes have no model binding and accept digits only); a booking that is not the user's
     * never gets this far, it is a 404 before.
     */
    public static function url(Request $request): string
    {
        $booking = $request->route('booking');

        if ($request->input(self::FIELD) === self::DETAIL && is_string($booking) && ctype_digit($booking)) {
            return route('provider.bookings.show', ['booking' => $booking]);
        }

        return route('provider.bookings.index');
    }
}
