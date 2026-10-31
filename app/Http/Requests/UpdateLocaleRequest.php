<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The language chosen with the language selector: only one of the available ones.
 */
class UpdateLocaleRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        // Anyone can choose the language, with or without an account.
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'locale' => ['required', 'string', Rule::in(array_keys(config()->array('app.available_locales')))],
        ];
    }

    /**
     * The chosen language, already validated.
     */
    public function locale(): string
    {
        return $this->string('locale')->toString();
    }
}
