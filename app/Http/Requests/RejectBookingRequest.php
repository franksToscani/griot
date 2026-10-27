<?php

namespace App\Http\Requests;

use App\Models\Booking;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The provider rejects a pending booking or cancels a confirmed one, with an optional message for
 * the customer: the same field for both actions. The booking is searched only among the user's
 * own: any other id gets a 404, before the validation.
 */
class RejectBookingRequest extends FormRequest
{
    /**
     * The longest message, in characters.
     */
    public const REASON_MAX = 500;

    /**
     * Back to the bookings page on any validation error: the Referer is not reliable.
     *
     * @var string
     */
    protected $redirectRoute = 'provider.bookings.index';

    private ?Booking $ownBooking = null;

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        // The controller checks BookingPolicy::manage on the booking.
        return true;
    }

    /**
     * The booking of the address, among the user's own; null for a user without a provider (the
     * controller sends them to the setup page).
     */
    public function ownBooking(): ?Booking
    {
        $provider = $this->user()?->provider;

        if ($provider === null) {
            return null;
        }

        return $this->ownBooking ??= $provider->bookings()->whereKey($this->route('booking'))->first()
            ?? abort(404);
    }

    /**
     * Prepare the data for validation: a booking that is not the user's gets a 404 now.
     */
    protected function prepareForValidation(): void
    {
        $this->ownBooking();
    }

    /**
     * Get the validation rules that apply to the request. The message is plain text: it is stored
     * as written and the pages show it as text.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'reason' => ['nullable', 'string', 'max:'.self::REASON_MAX],
        ];
    }

    /**
     * Messages in Italian.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'reason.max' => __('provider.bookings.validation.reason_max'),
        ];
    }

    /**
     * The message, or null when missing; ManageBooking trims it.
     */
    public function reason(): ?string
    {
        $reason = $this->validated('reason');

        return is_string($reason) ? $reason : null;
    }
}
