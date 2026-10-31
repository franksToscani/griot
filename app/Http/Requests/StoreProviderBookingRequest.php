<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\BookingRules;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A booking the logged-in provider writes in the own agenda, for a customer who asked by phone or
 * in person. Same fields and messages as the customer's form (BookingRules): name and phone are
 * required, email and notes are not. No honeypot: the route is behind the login.
 */
class StoreProviderBookingRequest extends FormRequest
{
    use BookingRules;

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        // The controller checks ProviderPolicy::update on the user's own provider.
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
        return [
            ...$this->bookingRules(),
            // The customer's language, for the WhatsApp text and the emails: one of the available ones.
            'customer_locale' => ['nullable', 'string', Rule::in(array_keys(config()->array('app.available_locales')))],
        ];
    }

    /**
     * The customer's language: the one chosen in the form, or the provider's own language now.
     */
    public function customerLocale(): string
    {
        $locale = $this->validated('customer_locale');

        return is_string($locale) && $locale !== '' ? $locale : app()->getLocale();
    }

    /**
     * Messages for the fields the provider fills in: the customer form's ones, except those that
     * speak to the customer ("your name"), which here speak about the customer.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            ...$this->bookingMessages(),
            'customer_name.required' => __('provider.bookings.validation.name_required'),
            'customer_locale.in' => __('provider.bookings.validation.customer_locale_invalid'),
        ];
    }
}
