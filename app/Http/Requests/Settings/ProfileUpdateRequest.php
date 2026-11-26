<?php

namespace App\Http\Requests\Settings;

use App\Concerns\ProfileValidationRules;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class ProfileUpdateRequest extends FormRequest
{
    use ProfileValidationRules;

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $rules = $this->profileRules($this->user()->id);

        // A provider has one name, the business one (providers.name), changed in the profile and
        // copied to the account (UpdateProviderProfile): a "name" sent here is left out of the
        // validated data, so it is never saved.
        if ($this->user()->provider()->exists()) {
            unset($rules['name']);
        }

        return $rules;
    }
}
