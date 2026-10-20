<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ProviderProfileRules;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The profile a logged-in user writes to create their provider. The fields, their messages and
 * details() come from ProviderProfileRules; the slug is chosen by CreateProviderProfile.
 */
class StoreProviderRequest extends FormRequest
{
    use ProviderProfileRules;

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        // The route is for logged-in, verified users; the provider is always their own.
        return true;
    }

    /**
     * Prepare the data for validation: the Instagram username is saved without "@".
     */
    protected function prepareForValidation(): void
    {
        $this->removeInstagramAt();
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return $this->providerProfileRules();
    }

    /**
     * Messages in Italian for every field.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return $this->providerProfileMessages();
    }
}
