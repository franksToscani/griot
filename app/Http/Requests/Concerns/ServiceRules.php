<?php

namespace App\Http\Requests\Concerns;

use Closure;
use Illuminate\Validation\Rule;

/**
 * The rules, the messages and the data of a service, shared by the request that creates it and
 * the one that edits it. provider_id, id, is_active and created_at are never in the rules: sent in
 * the body, they have no effect. The middleware has already trimmed the strings and turned the
 * empty ones into null.
 */
trait ServiceRules
{
    /**
     * Shortest and longest duration, in minutes, and the step between two durations.
     */
    private const DURATION_MIN = 5;

    private const DURATION_MAX = 720;

    private const DURATION_STEP = 5;

    /**
     * "25,50" is read as "25.50". The comma becomes a dot only when the whole price has the
     * accepted shape, so "1.000,50" or "25,5,0" stay as they are and are refused by the rule.
     */
    protected function preparePrice(): void
    {
        $price = $this->input('price');

        if (is_string($price)) {
            $price = trim($price);

            if (preg_match('/^\d{1,4},\d{1,2}$/', $price) === 1) {
                $price = str_replace(',', '.', $price);
            }

            $this->merge(['price' => $price]);
        }
    }

    /**
     * @return array<string, array<mixed>>
     */
    protected function serviceRules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string', 'max:1000'],
            // bail: one message. Categories are global: any existing one can be chosen.
            'category_id' => ['bail', 'required', 'integer', Rule::exists('categories', 'id')],
            // No "numeric": it would accept "1e3". Four digits, up to two decimals, above zero.
            'price' => ['bail', 'required', 'string', 'regex:/^\d{1,4}(\.\d{1,2})?$/', $this->positivePrice(...)],
            'duration_min' => ['bail', 'required', 'integer', $this->validDuration(...)],
            // The order is checked only once duration_max itself is valid (bail), and only if
            // duration_min is valid too: an invalid minimum gets its own error, not an "order" one.
            'duration_max' => ['bail', 'required', 'integer', $this->validDuration(...), $this->notShorterThanMinimum(...)],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function serviceMessages(): array
    {
        return [
            'name.required' => __('provider.services.validation.name_required'),
            'name.max' => __('provider.services.validation.name_max'),
            'description.max' => __('provider.services.validation.description_max'),
            'category_id.required' => __('provider.services.validation.category_required'),
            'category_id.integer' => __('provider.services.validation.category_invalid'),
            'category_id.exists' => __('provider.services.validation.category_invalid'),
            'price.required' => __('provider.services.validation.price_required'),
            'price.string' => __('provider.services.validation.price_invalid'),
            'price.regex' => __('provider.services.validation.price_invalid'),
            'duration_min.required' => __('provider.services.validation.duration_min_required'),
            'duration_min.integer' => __('provider.services.validation.duration_invalid'),
            'duration_max.required' => __('provider.services.validation.duration_max_required'),
            'duration_max.integer' => __('provider.services.validation.duration_invalid'),
        ];
    }

    /**
     * The service fields, written by hand from the validated data: never provider_id nor
     * is_active.
     *
     * @return array{category_id: int, name: string, description: ?string, price: string, duration_min: int, duration_max: int}
     */
    protected function serviceFields(): array
    {
        $description = $this->validated('description');

        return [
            'category_id' => $this->integer('category_id'),
            'name' => $this->string('name')->toString(),
            'description' => is_string($description) ? $description : null,
            'price' => $this->normalizedPrice($this->string('price')->toString()),
            'duration_min' => $this->integer('duration_min'),
            'duration_max' => $this->integer('duration_max'),
        ];
    }

    /**
     * Above zero: after the regex the price is only digits and one dot, so it is above zero when
     * at least one digit is not 0 ("0", "0.00" and "00.0" are refused).
     */
    private function positivePrice(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || preg_match('/[1-9]/', $value) !== 1) {
            $fail(__('provider.services.validation.price_invalid'));
        }
    }

    /**
     * From 5 to 720 minutes, in steps of 5.
     */
    private function validDuration(string $attribute, mixed $value, Closure $fail): void
    {
        if (! $this->isValidDuration($value)) {
            $fail(__('provider.services.validation.duration_invalid'));
        }
    }

    /**
     * The longest duration is not shorter than the shortest one. Runs after duration_max is valid;
     * if duration_min is invalid there is nothing to compare, and its own error is enough.
     */
    private function notShorterThanMinimum(string $attribute, mixed $value, Closure $fail): void
    {
        $minimum = $this->input('duration_min');

        if ($this->isValidDuration($minimum) && (int) $value < (int) $minimum) {
            $fail(__('provider.services.validation.duration_order'));
        }
    }

    private function isValidDuration(mixed $value): bool
    {
        $minutes = filter_var($value, FILTER_VALIDATE_INT);

        return $minutes !== false
            && $minutes >= self::DURATION_MIN
            && $minutes <= self::DURATION_MAX
            && $minutes % self::DURATION_STEP === 0;
    }

    /**
     * "25" becomes "25.00", "25.5" becomes "25.50", "0025" becomes "25.00", "0.01" stays.
     */
    private function normalizedPrice(string $price): string
    {
        [$units, $cents] = array_pad(explode('.', $price, 2), 2, '');
        $units = ltrim($units, '0');

        return ($units === '' ? '0' : $units).'.'.str_pad($cents, 2, '0');
    }
}
