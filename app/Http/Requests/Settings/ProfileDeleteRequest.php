<?php

namespace App\Http\Requests\Settings;

use App\Concerns\PasswordValidationRules;
use App\Profile\DeleteProviderAccount;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class ProfileDeleteRequest extends FormRequest
{
    use PasswordValidationRules;

    /**
     * Back to the settings page, never the Referer.
     *
     * @var string
     */
    protected $redirectRoute = 'profile.edit';

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'password' => $this->currentPasswordRules(),
        ];
    }

    /**
     * A provider with bookings, in any status and anonymous ones too, cannot delete the account
     * (issue #133): the error "account", before the controller logs anybody out.
     *
     * @return array<int, Closure(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $user = $this->user();

                if ($user !== null && DeleteProviderAccount::hasBookings($user)) {
                    $validator->errors()->add('account', DeleteProviderAccount::refusalMessage());
                }
            },
        ];
    }
}
