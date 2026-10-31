<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\BookingRules;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A booking sent by a customer from the provider page. The fields, their messages and the
 * phone handling come from BookingRules; the honeypot is this request's only.
 */
class StoreBookingRequest extends FormRequest
{
    use BookingRules;

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        // Customers book without an account.
        return true;
    }

    /**
     * Prepare the data for validation: the phone number in international format.
     */
    protected function prepareForValidation(): void
    {
        $this->normalizeCustomerPhone();
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return $this->bookingRules();
    }

    /**
     * Messages for the fields the customer fills in.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return $this->bookingMessages();
    }

    /**
     * Whether the hidden trap field (honeypot) was filled in: a person never sees it, a bot that
     * fills every field does. It is not in rules() and is read from the raw input, not from
     * validated(). Empty or only spaces is not filled; an array (confirm_reference[]=x) is.
     */
    public function honeypotFilled(): bool
    {
        return $this->filled('confirm_reference');
    }
}
