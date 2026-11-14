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

    /**
     * The page where the choice was made, sent by the selector ("return_to", usePage().url), or
     * null. Not in rules() on purpose: a refused address must not refuse the language too, so it is
     * only ignored, and the controller goes back() as before.
     *
     * Only a path of this site, checked strictly so that the redirect can never leave it:
     * - it starts with exactly one "/": not "//evil.test" nor "/\evil.test", which browsers read as
     *   another host, and never a scheme such as "https:" or "javascript:";
     * - no "\" anywhere, no control character (line breaks and tabs included);
     * - at most 2048 characters.
     */
    public function returnTo(): ?string
    {
        $value = $this->input('return_to');

        if (! is_string($value) || strlen($value) > 2048) {
            return null;
        }

        return preg_match('~^/[^/\\\\\x00-\x1F\x7F][^\\\\\x00-\x1F\x7F]*$|^/$~D', $value) === 1 ? $value : null;
    }
}
