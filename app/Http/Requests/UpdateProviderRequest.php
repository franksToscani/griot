<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ProviderProfileRules;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The profile a provider edits: the same fields as the creation, plus the address of the page
 * (slug). The provider is always the logged-in user's own, never read from the input.
 */
class UpdateProviderRequest extends FormRequest
{
    use ProviderProfileRules;

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        // The controller checks ProviderPolicy::update, after sending a user without a provider
        // to the setup page.
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
        return [
            ...$this->providerProfileRules(),
            // bail: one message per wrong slug. Unique among the providers, ignoring this one:
            // its id comes from the logged-in user, never from the input.
            'slug' => [
                'bail',
                'required',
                'string',
                'max:120',
                'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                Rule::unique('providers', 'slug')->ignore($this->user()?->provider?->id),
            ],
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
            ...$this->providerProfileMessages(),
            'slug.required' => __('provider.validation.slug_required'),
            'slug.string' => __('provider.validation.slug_invalid'),
            'slug.max' => __('provider.validation.slug_max'),
            'slug.regex' => __('provider.validation.slug_invalid'),
            'slug.unique' => __('provider.validation.slug_taken'),
        ];
    }

    /**
     * The validated address of the page.
     */
    public function slug(): string
    {
        return $this->string('slug')->toString();
    }
}
