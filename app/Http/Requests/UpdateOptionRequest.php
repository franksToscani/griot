<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\OptionRules;
use App\Models\ServiceOption;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * An option edited by its provider. The option is searched only among those of the user's own
 * service: any other id gets a 404, before the validation.
 */
class UpdateOptionRequest extends FormRequest
{
    use OptionRules;

    private ?ServiceOption $ownOption = null;

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        // The controller checks ServiceOptionPolicy::update on the option.
        return true;
    }

    /**
     * The option of the address, among those of the user's own service; null for a user without a
     * provider.
     */
    public function ownOption(): ?ServiceOption
    {
        $service = $this->ownService();

        if ($service === null) {
            return null;
        }

        return $this->ownOption ??= $service->options()->whereKey($this->route('option'))->first()
            ?? abort(404);
    }

    /**
     * Prepare the data for validation: a service or an option that is not the user's gets a 404
     * now; "7,50" is read as "7.50".
     */
    protected function prepareForValidation(): void
    {
        $this->ownOption();
        $this->prepareExtraPrice();
    }

    /**
     * Get the validation rules that apply to the request. The name may stay the same: the
     * uniqueness check ignores this option.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return $this->optionRules($this->ownOption()?->id);
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
}
