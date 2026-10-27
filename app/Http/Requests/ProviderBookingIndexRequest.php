<?php

namespace App\Http\Requests;

use App\Enums\BookingStatus;
use Carbon\CarbonImmutable;
use Carbon\Exceptions\InvalidFormatException;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Filters of the provider's bookings, all optional, in the query string. An invalid one goes back
 * to the list without filters, with the message on its field; a JSON client gets a 422. The
 * middleware has already turned the empty ones into null.
 */
class ProviderBookingIndexRequest extends FormRequest
{
    /**
     * The longest range, in days, both ends included.
     */
    public const MAX_RANGE_DAYS = 366;

    /**
     * Back to the list without filters: the previous address is not reliable, and the filters of
     * this one are the invalid ones.
     *
     * @var string
     */
    protected $redirectRoute = 'provider.bookings.index';

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        // The controller checks ProviderPolicy::update on the user's provider.
        return true;
    }

    /**
     * Get the validation rules that apply to the request. The dates are local calendar days of the
     * provider; order and length are checked only when both are given.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'status' => ['bail', 'nullable', 'string', Rule::enum(BookingStatus::class)],
            'from' => ['bail', 'nullable', 'string', 'date_format:Y-m-d'],
            'to' => ['bail', 'nullable', 'string', 'date_format:Y-m-d', $this->validRange(...)],
        ];
    }

    /**
     * Messages in Italian for every field.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'status.string' => __('provider.bookings.validation.status_invalid'),
            'status.enum' => __('provider.bookings.validation.status_invalid'),
            'from.string' => __('provider.bookings.validation.date_invalid'),
            'from.date_format' => __('provider.bookings.validation.date_invalid'),
            'to.string' => __('provider.bookings.validation.date_invalid'),
            'to.date_format' => __('provider.bookings.validation.date_invalid'),
        ];
    }

    /**
     * The filters, after the validation; a missing one is null.
     *
     * @return array{status: string|null, from: string|null, to: string|null}
     */
    public function filters(): array
    {
        return [
            'status' => $this->validatedString('status'),
            'from' => $this->validatedString('from'),
            'to' => $this->validatedString('to'),
        ];
    }

    /**
     * Runs after "to" is a valid date, and only if "from" is one too: an invalid "from" has its
     * own error.
     */
    private function validRange(string $attribute, mixed $value, Closure $fail): void
    {
        $from = $this->calendarDay($this->input('from'));
        $to = $this->calendarDay($value);

        if ($from === null || $to === null) {
            return;
        }

        if ($to->lessThan($from)) {
            $fail(__('provider.bookings.validation.range_order'));

            return;
        }

        // Both ends included: from 1 January to 31 December of a leap year is 366 days.
        if ($from->diffInDays($to) + 1 > self::MAX_RANGE_DAYS) {
            $fail(__('provider.bookings.validation.range_too_long'));
        }
    }

    /**
     * A "Y-m-d" date as a plain calendar day, or null if it is not one. Read in UTC: only the
     * number of days between two dates matters here, never a time.
     */
    private function calendarDay(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value)) {
            return null;
        }

        try {
            $day = CarbonImmutable::createFromFormat('!Y-m-d', $value, 'UTC');
        } catch (InvalidFormatException) {
            // Carbon throws on a value that does not fit the format at all.
            return null;
        }

        // Carbon rolls "2026-02-30" over to March: the date must read back the same.
        return $day !== null && $day->format('Y-m-d') === $value ? $day : null;
    }

    private function validatedString(string $key): ?string
    {
        $value = $this->validated($key);

        return is_string($value) ? $value : null;
    }
}
