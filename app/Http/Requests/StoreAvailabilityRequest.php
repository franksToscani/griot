<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\AvailabilityRules;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A new weekly time slot of the logged-in provider. The fields come from AvailabilityRules.
 */
class StoreAvailabilityRequest extends FormRequest
{
    use AvailabilityRules;

    /**
     * Back to the hours page on any validation error: the Referer is not reliable.
     *
     * @var string
     */
    protected $redirectRoute = 'provider.availability.index';

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        // The controller checks ProviderPolicy::update on the user's provider.
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return $this->availabilityRules();
    }

    /**
     * Messages in Italian for every field.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return $this->availabilityMessages();
    }

    protected function ignoredAvailabilityId(): ?int
    {
        return null;
    }
}
