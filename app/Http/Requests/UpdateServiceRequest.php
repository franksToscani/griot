<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ServiceRules;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A service edited by its provider: the same fields as the creation. is_active is changed only by
 * the deactivate and activate actions, never here.
 */
class UpdateServiceRequest extends FormRequest
{
    use ServiceRules;

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        // The controller finds the service among the user's own and checks ServicePolicy::update.
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
     * Only the service fields: no is_active, no provider_id.
     *
     * @return array{category_id: int, name: string, description: ?string, price: string, duration_min: int, duration_max: int}
     */
    public function serviceData(): array
    {
        return $this->serviceFields();
    }
}
