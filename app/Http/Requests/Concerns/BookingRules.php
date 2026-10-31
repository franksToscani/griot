<?php

namespace App\Http\Requests\Concerns;

use App\Booking\CustomerDetails;
use App\Booking\PhoneNumber;
use Carbon\CarbonImmutable;
use Closure;
use LogicException;

/**
 * The fields of a new booking, shared by the customer's request (public page) and the provider's
 * one (own agenda): service, options, start and the customer's details. They check the shape of
 * the data only: whether the service, the options and the time can be booked is decided afterwards.
 * provider_id, status, created_by_provider, ends_at and public_token are never in the rules: sent
 * in the body, they have no effect.
 *
 * The fields a person fills in have Italian messages; the format errors that only a wrong client
 * can cause (service, options, starts_at format) keep the framework's default messages.
 */
trait BookingRules
{
    /**
     * The format of "start" in the free slots endpoints, for example 2026-10-27T08:00:00Z.
     */
    private const STARTS_AT_FORMAT = 'Y-m-d\TH:i:s\Z';

    /**
     * A phone number that can be read is replaced by its international format (+393331234567),
     * which is what is validated and saved. One that cannot be read is left as it is, and the rule
     * below refuses it.
     */
    protected function normalizeCustomerPhone(): void
    {
        $phone = $this->input('customer_phone');
        $normalized = is_string($phone) ? PhoneNumber::normalize($phone) : null;

        if ($normalized !== null) {
            $this->merge(['customer_phone' => $normalized]);
        }
    }

    /**
     * @return array<string, array<mixed>>
     */
    protected function bookingRules(): array
    {
        return [
            'service' => ['required', 'integer'],
            'options' => ['sometimes', 'array', 'max:20'],
            'options.*' => ['integer'],
            'starts_at' => ['required', 'date_format:'.self::STARTS_AT_FORMAT],
            'customer_name' => ['required', 'string', 'max:150'],
            // bail: one message for a wrong number, even when several rules fail.
            'customer_phone' => ['bail', 'required', 'string', $this->internationalPhone(...)],
            'customer_email' => ['nullable', 'email', 'max:255'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function bookingMessages(): array
    {
        return [
            'starts_at.required' => __('booking.validation.starts_at_required'),
            'customer_name.required' => __('booking.validation.name_required'),
            'customer_name.max' => __('booking.validation.name_max'),
            'customer_phone.required' => __('booking.validation.phone_required'),
            'customer_phone.string' => __('booking.phone.invalid'),
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
     * The customer's details: name trimmed, phone in international format, empty email and notes as null.
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
     * The number is valid when normalizeCustomerPhone() could put it in international format: the
     * rule is PhoneNumber's only, and a normalized number comes back the same.
     */
    private function internationalPhone(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || PhoneNumber::normalize($value) !== $value) {
            $fail(__('booking.phone.invalid'));
        }
    }
}
