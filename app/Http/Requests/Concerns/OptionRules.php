<?php

namespace App\Http\Requests\Concerns;

use App\Models\Service;
use Closure;
use Illuminate\Validation\Rule;

/**
 * The rules, the messages and the data of a service option, shared by the requests that create and
 * edit it. The service comes from the logged-in user's provider, never from the input: a service
 * of another provider, or a missing one, gets a 404 here, before the validation. service_id, id,
 * is_active and created_at in the body have no effect.
 */
trait OptionRules
{
    use DecimalPrice;

    /**
     * The longest extra time, in minutes, and the step between two values (0 is allowed).
     */
    private const EXTRA_DURATION_MAX = 240;

    private const EXTRA_DURATION_STEP = 5;

    private ?Service $ownService = null;

    /**
     * The service of the address, among the logged-in user's own; null for a user without a
     * provider (the controller sends them to the setup page).
     */
    public function ownService(): ?Service
    {
        $provider = $this->user()?->provider;

        if ($provider === null) {
            return null;
        }

        return $this->ownService ??= $provider->services()->whereKey($this->route('service'))->first()
            ?? abort(404);
    }

    /**
     * "7,50" is read as "7.50", for an extra price of up to three digits (see DecimalPrice).
     */
    protected function prepareExtraPrice(): void
    {
        $this->commaToDot('extra_price', 3);
    }

    /**
     * @return array<string, array<mixed>>
     */
    protected function optionRules(?int $ignoreOptionId = null): array
    {
        return [
            // Unique among the options of the same service: the same name in another service is fine.
            'name' => [
                'required',
                'string',
                'max:150',
                Rule::unique('service_options', 'name')
                    ->where('service_id', $this->ownService()?->id)
                    ->ignore($ignoreOptionId),
            ],
            // No "numeric": it would accept "1e2". Up to three digits and two decimals; zero is fine.
            'extra_price' => ['bail', 'required', 'string', 'regex:/^\d{1,3}(\.\d{1,2})?$/'],
            'extra_duration' => ['bail', 'required', 'integer', $this->validExtraDuration(...)],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function optionMessages(): array
    {
        return [
            'name.required' => __('provider.options.validation.name_required'),
            'name.max' => __('provider.options.validation.name_max'),
            'name.unique' => __('provider.options.validation.name_taken'),
            'extra_price.required' => __('provider.options.validation.price_required'),
            'extra_price.string' => __('provider.options.validation.price_invalid'),
            'extra_price.regex' => __('provider.options.validation.price_invalid'),
            'extra_duration.required' => __('provider.options.validation.duration_required'),
            'extra_duration.integer' => __('provider.options.validation.duration_invalid'),
        ];
    }

    /**
     * The attributes of the option, written by hand from the validated data.
     *
     * @return array{name: string, extra_price: string, extra_duration: int}
     */
    public function optionData(): array
    {
        return [
            'name' => $this->string('name')->toString(),
            'extra_price' => $this->normalizedPrice($this->string('extra_price')->toString()),
            'extra_duration' => $this->integer('extra_duration'),
        ];
    }

    /**
     * From 0 to 240 minutes, in steps of 5.
     */
    private function validExtraDuration(string $attribute, mixed $value, Closure $fail): void
    {
        $minutes = filter_var($value, FILTER_VALIDATE_INT);

        if ($minutes === false || $minutes < 0 || $minutes > self::EXTRA_DURATION_MAX || $minutes % self::EXTRA_DURATION_STEP !== 0) {
            $fail(__('provider.options.validation.duration_invalid'));
        }
    }
}
