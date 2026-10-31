<?php

namespace App\Http\Middleware;

use App\Http\LocaleFromRequest;
use App\Models\Booking;
use App\Support\Locales;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The private page of a booking, and its cancellation, in the customer's language when this
 * browser has made no choice: the link is often opened on another phone, from an email or a
 * WhatsApp message. A choice in the "locale" cookie still wins. Only a language available now.
 */
class UseBookingLocale
{
    /**
     * Handle an incoming request. The booking is already bound by its public_token.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $booking = $request->route('booking');

        if ($booking instanceof Booking && LocaleFromRequest::resolve($request) === null) {
            $locale = Locales::available($booking->locale);

            if ($locale !== null) {
                app()->setLocale($locale);
            }
        }

        return $next($request);
    }
}
