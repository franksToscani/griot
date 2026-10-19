<?php

namespace App\Http\Controllers;

use App\Http\Resources\BookingConfirmationResource;
use App\Models\Booking;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The private page of a booking, reached only with the secret code of its link.
 */
class BookingConfirmationController extends Controller
{
    /**
     * Handle the incoming request. The booking is found by public_token (route binding), never
     * by id, whatever its status and even if the provider is no longer published or the service
     * no longer active: the customer has already booked and the link must keep working.
     */
    public function __invoke(Request $request, Booking $booking): Response
    {
        // Three queries in all, however many options were chosen.
        $booking->load(['provider', 'service', 'options']);

        return Inertia::render('bookings/show', [
            // resolve() gives the booking's fields directly, without a "data" wrapper.
            'booking' => (new BookingConfirmationResource($booking))->resolve($request),
            // The only prop with the code: the page shows and copies the whole address.
            // url() leaves out the query string.
            'private_url' => $request->url(),
        ]);
    }
}
