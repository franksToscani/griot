<?php

namespace App\Http\Requests;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use LogicException;

/**
 * The parameters of the free slots endpoints, the public one and the provider's own. It checks only
 * the shape of the data: the business rules (booking window, service of the provider, options of the
 * service) are checked afterwards. Format errors keep the framework's default messages: they come
 * from a wrong client, not a person.
 */
class ProviderSlotsRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        // The public free slots are open to everyone; the provider's own route is behind the login.
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
            'service' => ['required', 'integer'],
            // date_format also rejects impossible dates such as 2026-02-30.
            'date' => ['required', 'date_format:Y-m-d'],
            'options' => ['sometimes', 'array', 'max:20'],
            'options.*' => ['integer'],
        ];
    }

    /**
     * The id of the chosen service; the query string sends it as a string.
     */
    public function serviceId(): int
    {
        return $this->integer('service');
    }

    /**
     * The ids of the chosen options, as integers; none when the parameter is missing.
     *
     * @return list<int>
     */
    public function optionIds(): array
    {
        $options = $this->validated('options', []);

        return is_array($options) ? array_values(array_map(intval(...), $options)) : [];
    }

    /**
     * The requested day: a local calendar date of the provider, at midnight in the booking time zone.
     */
    public function day(): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('!Y-m-d', $this->string('date')->toString(), config()->string('booking.timezone'))
            ?? throw new LogicException('A validated date could not be read.');
    }
}
