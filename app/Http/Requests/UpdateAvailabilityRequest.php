<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\AvailabilityRules;
use App\Models\Availability;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A weekly time slot edited by its provider. The slot is searched only among the user's own: any
 * other id gets a 404, before the validation.
 */
class UpdateAvailabilityRequest extends FormRequest
{
    use AvailabilityRules;

    /**
     * Back to the hours page on any validation error: the Referer is not reliable.
     *
     * @var string
     */
    protected $redirectRoute = 'provider.availability.index';

    private ?Availability $ownAvailability = null;

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        // The controller checks AvailabilityPolicy::update on the slot.
        return true;
    }

    /**
     * The slot of the address, among the user's own; null for a user without a provider (the
     * controller sends them to the setup page).
     */
    public function ownAvailability(): ?Availability
    {
        $provider = $this->user()?->provider;

        if ($provider === null) {
            return null;
        }

        return $this->ownAvailability ??= $provider->availabilities()->whereKey($this->route('availability'))->first()
            ?? abort(404);
    }

    /**
     * Prepare the data for validation: a slot that is not the user's gets a 404 now.
     */
    protected function prepareForValidation(): void
    {
        $this->ownAvailability();
    }

    /**
     * Get the validation rules that apply to the request. The slot may keep its day and times: it
     * never conflicts with itself.
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
        return $this->ownAvailability()?->id;
    }
}
