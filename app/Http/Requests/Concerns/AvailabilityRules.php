<?php

namespace App\Http\Requests\Concerns;

use App\Enums\Weekday;
use App\Models\Provider;
use App\Profile\ManageAvailability;
use Closure;
use Illuminate\Validation\Rule;

/**
 * The rules, the messages and the data of a weekly time slot, shared by the requests that create
 * and edit it. The provider is the logged-in user's, never from the input: provider_id and id in
 * the body have no effect. The middleware has already trimmed the strings and turned the empty
 * ones into null.
 *
 * Limit and overlap are only a courtesy check here, before the transaction: the real one is in
 * ManageAvailability, after the lock on the provider, with the same messages.
 */
trait AvailabilityRules
{
    /**
     * "HH:MM" from 00:00 to 23:59; the minutes are then checked to be a multiple of five.
     */
    private const TIME_PATTERN = '/^([01]\d|2[0-3]):[0-5]\d$/';

    private const TIME_STEP = 5;

    /**
     * The slot being edited, which never conflicts with itself; null when creating.
     */
    abstract protected function ignoredAvailabilityId(): ?int;

    /**
     * @return array<string, array<mixed>>
     */
    protected function availabilityRules(): array
    {
        return [
            'weekday' => ['bail', 'required', 'integer', Rule::enum(Weekday::class), $this->dayNotFull(...)],
            'start_time' => ['bail', 'required', 'string', 'regex:'.self::TIME_PATTERN, $this->onTimeStep(...), $this->noOverlap(...)],
            // The order is checked only once end_time itself is valid (bail), and only if
            // start_time is valid too: an invalid start gets its own error, not an "order" one.
            'end_time' => ['bail', 'required', 'string', 'regex:'.self::TIME_PATTERN, $this->onTimeStep(...), $this->afterStart(...)],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function availabilityMessages(): array
    {
        return [
            'weekday.required' => __('provider.availability.validation.weekday_invalid'),
            'weekday.integer' => __('provider.availability.validation.weekday_invalid'),
            'weekday.enum' => __('provider.availability.validation.weekday_invalid'),
            'start_time.required' => __('provider.availability.validation.time_required'),
            'start_time.string' => __('provider.availability.validation.time_invalid'),
            'start_time.regex' => __('provider.availability.validation.time_invalid'),
            'end_time.required' => __('provider.availability.validation.time_required'),
            'end_time.string' => __('provider.availability.validation.time_invalid'),
            'end_time.regex' => __('provider.availability.validation.time_invalid'),
        ];
    }

    /**
     * The attributes of the slot, written by hand from the validated data: "09:00" is saved as
     * "09:00:00", the format of the time columns.
     *
     * @return array{weekday: int, start_time: string, end_time: string}
     */
    public function availabilityData(): array
    {
        return [
            'weekday' => $this->integer('weekday'),
            'start_time' => $this->string('start_time')->toString().':00',
            'end_time' => $this->string('end_time')->toString().':00',
        ];
    }

    private function onTimeStep(string $attribute, mixed $value, Closure $fail): void
    {
        // After the regex: the value is "HH:MM".
        if ((int) substr((string) $value, 3, 2) % self::TIME_STEP !== 0) {
            $fail(__('provider.availability.validation.time_invalid'));
        }
    }

    /**
     * A slot that crosses midnight (22:00-02:00) ends before it starts: refused with the same
     * message, it is not supported.
     */
    private function afterStart(string $attribute, mixed $value, Closure $fail): void
    {
        $start = $this->input('start_time');

        if ($this->isValidTime($start) && (string) $value <= $start) {
            $fail(__('provider.availability.validation.end_before_start'));
        }
    }

    private function dayNotFull(string $attribute, mixed $value, Closure $fail): void
    {
        $provider = $this->ownProvider();

        if ($provider !== null && app(ManageAvailability::class)->isFull($provider, (int) $value, $this->ignoredAvailabilityId())) {
            $fail(__('provider.availability.validation.limit_reached', ['max' => ManageAvailability::MAX_PER_DAY]));
        }
    }

    /**
     * Only when day and both times are valid and in order: otherwise their own errors are enough.
     */
    private function noOverlap(string $attribute, mixed $value, Closure $fail): void
    {
        $provider = $this->ownProvider();
        $weekday = Weekday::tryFrom((int) filter_var($this->input('weekday'), FILTER_VALIDATE_INT));
        $start = $this->input('start_time');
        $end = $this->input('end_time');

        if ($provider === null || $weekday === null || ! $this->isValidTime($start) || ! $this->isValidTime($end) || $end <= $start) {
            return;
        }

        if (app(ManageAvailability::class)->overlaps($provider, $weekday->value, $start.':00', $end.':00', $this->ignoredAvailabilityId())) {
            $fail(__('provider.availability.validation.overlap'));
        }
    }

    /**
     * @phpstan-assert-if-true string $value
     */
    private function isValidTime(mixed $value): bool
    {
        return is_string($value)
            && preg_match(self::TIME_PATTERN, $value) === 1
            && (int) substr($value, 3, 2) % self::TIME_STEP === 0;
    }

    /**
     * The logged-in user's provider; null without one (the controller sends them to the setup page,
     * and there is nothing to compare with).
     */
    private function ownProvider(): ?Provider
    {
        return $this->user()?->provider;
    }
}
