<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\OptionRules;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A new option of one of the logged-in provider's services. The fields come from OptionRules.
 */
class StoreOptionRequest extends FormRequest
{
    use OptionRules;

    /**
     * At most this many options per service, shown or hidden: the same number of options the
     * booking form and the slots accept (ProviderSlotsRequest and StoreBookingRequest, max:20).
     */
    public const MAX_OPTIONS = 20;

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        // The controller checks ServicePolicy::update on the service.
        return true;
    }

    /**
     * Prepare the data for validation: a service that is not the user's gets a 404 now; "7,50" is
     * read as "7.50".
     */
    protected function prepareForValidation(): void
    {
        $this->ownService();
        $this->prepareExtraPrice();
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $rules = $this->optionRules();
        // A courtesy check, before the transaction: the real one is in the controller, after the
        // lock on the service, with the same message.
        $rules['name'][] = $this->belowLimit(...);

        return $rules;
    }

    /**
     * Messages in Italian for every field.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return $this->optionMessages();
    }

    private function belowLimit(string $attribute, mixed $value, Closure $fail): void
    {
        $service = $this->ownService();

        if ($service !== null && $service->options()->count() >= self::MAX_OPTIONS) {
            $fail(__('provider.options.validation.limit_reached', ['max' => self::MAX_OPTIONS]));
        }
    }
}
