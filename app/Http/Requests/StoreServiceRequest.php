<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ServiceRules;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A new service written by the logged-in provider. The fields, their messages and the price
 * handling come from ServiceRules.
 */
class StoreServiceRequest extends FormRequest
{
    use ServiceRules;

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        // The controller checks ProviderPolicy::update on the user's own provider.
        return true;
    }

    /**
     * Prepare the data for validation: "25,50" is read as "25.50".
     */
    protected function prepareForValidation(): void
    {
        $this->preparePrice();
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return $this->serviceRules();
    }

    /**
     * Messages in Italian for every field.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return $this->serviceMessages();
    }

    /**
     * The attributes of the new service. is_active is always true here; the provider is set by the
     * relation, never by the input.
     *
     * @return array{category_id: int, name: string, description: ?string, price: string, duration_min: int, duration_max: int, is_active: true}
     */
    public function serviceData(): array
    {
        return [
            ...$this->serviceFields(),
            'is_active' => true,
        ];
    }
}
