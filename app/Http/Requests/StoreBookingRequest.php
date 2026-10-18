<?php

namespace App\Http\Requests;

use App\Booking\CustomerDetails;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use LogicException;

/**
 * A booking sent by a customer from the provider page. It checks the shape of the data only:
 * whether the service, the options and the time can be booked is decided afterwards.
 *
 * The fields the customer fills in have Italian messages; the format errors that only a wrong
 * client can cause (service, options, starts_at format) keep the framework's default messages.
 */
class StoreBookingRequest extends FormRequest
{
    /**
     * The format of "start" in the free slots endpoint, for example 2026-10-27T08:00:00Z.
     */
    private const STARTS_AT_FORMAT = 'Y-m-d\TH:i:s\Z';

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        // Customers book without an account.
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'service' => ['required', 'integer'],
            'options' => ['sometimes', 'array', 'max:20'],
            'options.*' => ['integer'],
            'starts_at' => ['required', 'date_format:'.self::STARTS_AT_FORMAT],
            'customer_name' => ['required', 'string', 'max:150'],
            // bail: one message for a wrong number, even when several rules fail.
            'customer_phone' => ['bail', 'required', 'string', 'max:30', 'regex:/^[0-9 +().\-]+$/', $this->phoneDigits(...)],
            'customer_email' => ['nullable', 'email', 'max:255'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * Messages for the fields the customer fills in.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'starts_at.required' => __('booking.validation.starts_at_required'),
            'customer_name.required' => __('booking.validation.name_required'),
            'customer_name.max' => __('booking.validation.name_max'),
            'customer_phone.required' => __('booking.validation.phone_required'),
            'customer_phone.max' => __('booking.validation.phone_invalid'),
            'customer_phone.regex' => __('booking.validation.phone_invalid'),
            'customer_email.email' => __('booking.validation.email_invalid'),
            'customer_email.max' => __('booking.validation.email_invalid'),
            'notes.max' => __('booking.validation.notes_max'),
        ];
    }

    /**
     * The id of the chosen service; the request may send it as a string.
     */
    public function serviceId(): int
    {
        return $this->integer('service');
    }

    /**
     * The ids of the chosen options, as integers; none when the field is missing.
     *
     * @return list<int>
     */
    public function optionIds(): array
    {
        $options = $this->validated('options', []);

        return is_array($options) ? array_values(array_map(intval(...), $options)) : [];
    }

    /**
     * The chosen start, read strictly in the slots endpoint format, in UTC.
     */
    public function startsAt(): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('!'.self::STARTS_AT_FORMAT, $this->string('starts_at')->toString(), 'UTC')
            ?? throw new LogicException('A validated start could not be read.');
    }

    /**
     * The customer's details: name and phone trimmed and saved as written, empty email and notes as null.
     */
    public function customer(): CustomerDetails
    {
        $email = trim($this->string('customer_email')->toString());
        $notes = trim($this->string('notes')->toString());

        return new CustomerDetails(
            name: trim($this->string('customer_name')->toString()),
            phone: trim($this->string('customer_phone')->toString()),
            email: $email !== '' ? $email : null,
            notes: $notes !== '' ? $notes : null,
        );
    }

    /**
     * A phone number has from 6 to 15 digits, whatever spaces and symbols surround them.
     */
    private function phoneDigits(string $attribute, mixed $value, Closure $fail): void
    {
        $digits = is_string($value) ? preg_match_all('/\d/', $value) : 0;

        if ($digits < 6 || $digits > 15) {
            $fail(__('booking.validation.phone_invalid'));
        }
    }
}
