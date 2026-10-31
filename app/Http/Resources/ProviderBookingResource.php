<?php

namespace App\Http\Resources;

use App\Booking\BookingOutcome;
use App\Booking\BookingTransitions;
use App\Booking\LocalTime;
use App\Booking\PhoneNumber;
use App\Models\Booking;
use App\Models\ServiceOption;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A booking in the provider's own list. It expects provider, service and options to be already
 * loaded. It leaves out on purpose every internal id but the booking's. The public_token appears in
 * one place only: inside whatsapp_url, the private address the provider sends to the customer,
 * and only for a booking the provider has answered. The customer's fields are data written by
 * the customer: the page shows them as plain text.
 *
 * @mixin Booking
 */
class ProviderBookingResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $start = LocalTime::fromUtc($this->starts_at);
        $end = LocalTime::fromUtc($this->ends_at);
        $options = $this->options->sortBy('id')->values();
        $transitions = app(BookingTransitions::class);
        // The provider's answer, read once: the page and the WhatsApp message both use it.
        $outcome = BookingOutcome::of($this->resource);

        return [
            'id' => $this->id,
            'status' => $this->status->value,
            'local_date' => $start->format('Y-m-d'),
            'local_start' => $start->format('H:i'),
            'local_end' => $end->format('H:i'),
            'customer_name' => $this->customer_name,
            'customer_phone' => $this->customer_phone,
            'customer_email' => $this->customer_email,
            'notes' => $this->notes,
            'service' => [
                'name' => $this->service->name,
            ],
            'options' => $options
                ->map(fn (ServiceOption $option) => [
                    'name' => $option->name,
                    // Decimal string such as "7.50".
                    'extra_price' => $option->extra_price,
                ])
                ->all(),
            // The CURRENT prices of the service and of the options chosen: the booking does not
            // keep the prices of the day it was made.
            'price_cents' => $options->reduce(
                fn (int $total, ServiceOption $option) => $total + self::cents($option->extra_price),
                self::cents($this->service->price),
            ),
            // Which buttons the page shows, from the same rules the actions check again. No query.
            'can_confirm' => $transitions->canConfirm($this->resource),
            'can_reject' => $transitions->canReject($this->resource),
            'can_cancel' => $transitions->canCancelAsProvider($this->resource),
            // confirmed, rejected or cancelled; null without an answer of the provider (pending,
            // cancelled by the customer, or cancelled before cancelled_by existed). cancelled_by
            // itself stays on the server.
            'outcome' => $outcome?->value,
            'whatsapp_url' => $this->whatsappUrl($outcome, $start, $end),
        ];
    }

    /**
     * The WhatsApp chat with the customer, with the provider's answer already written: the
     * provider opens it and sends it by hand. Null for a booking without an answer (pending, or
     * cancelled by the customer or before cancelled_by existed) and for a number that cannot be
     * put in international format. The message holds the private address of the booking.
     */
    private function whatsappUrl(?BookingOutcome $outcome, CarbonImmutable $start, CarbonImmutable $end): ?string
    {
        // The numbers saved before the form normalized them are read with the same rule.
        $phone = PhoneNumber::normalize($this->customer_phone);

        if ($outcome === null || $phone === null) {
            return null;
        }

        $lines = [__('provider.bookings.whatsapp_text_'.$outcome->value, [
            'name' => $this->customer_name,
            'provider' => $this->provider->name,
            'service' => $this->service->name,
            // As in the emails: "giovedì 29 ottobre 2026, 09:00–11:45".
            'when' => $start->isoFormat('dddd D MMMM YYYY').', '.$start->format('H:i').'–'.$end->format('H:i'),
        ])];

        if ($outcome !== BookingOutcome::Confirmed && $this->cancellation_reason !== null) {
            $lines[] = __('provider.bookings.whatsapp_reason', ['reason' => $this->cancellation_reason]);
        }

        $lines[] = __('provider.bookings.whatsapp_link', ['url' => route('bookings.show', ['booking' => $this->public_token])]);

        // wa.me wants the digits without "+". rawurlencode() writes a space as %20, which every
        // WhatsApp app reads as a space ("+" from urlencode() can stay a "+").
        return 'https://wa.me/'.substr($phone, 1).'?text='.rawurlencode(implode("\n\n", $lines));
    }

    /**
     * "7.50" becomes 750, with integers only: adding decimals as floating point numbers can give
     * results such as 0.1 + 0.2 = 0.30000000000000004.
     */
    private static function cents(string $price): int
    {
        [$units, $decimals] = array_pad(explode('.', $price, 2), 2, '');

        return (int) $units * 100 + (int) str_pad(substr($decimals, 0, 2), 2, '0');
    }
}
