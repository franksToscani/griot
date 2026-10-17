<?php

namespace App\Http\Requests;

use App\Models\Category;
use App\Models\Provider;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Filters of the public provider list. They are optional and never reject the page:
 * a value that is not one of the offered options is ignored.
 */
class ProviderIndexRequest extends FormRequest
{
    /** @var Collection<int, Category>|null */
    private ?Collection $categoryOptions = null;

    /** @var list<string>|null */
    private ?array $cityOptions = null;

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        // The list is public.
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
            'category' => ['nullable', 'string', Rule::in($this->categoryOptions()->pluck('slug'))],
            'city' => ['nullable', 'string', Rule::in($this->cityOptions())],
        ];
    }

    /**
     * An invalid filter is dropped instead of redirecting back with an error.
     */
    protected function failedValidation(Validator $validator): void
    {
        //
    }

    /**
     * The valid filters; an invalid or missing one is null.
     *
     * @return array{category: string|null, city: string|null}
     */
    public function filters(): array
    {
        $failed = $this->getValidatorInstance()->failed();

        return [
            'category' => $this->validFilter('category', $failed),
            'city' => $this->validFilter('city', $failed),
        ];
    }

    /**
     * Categories with at least one active service of a published provider, by name.
     *
     * @return Collection<int, Category>
     */
    public function categoryOptions(): Collection
    {
        return $this->categoryOptions ??= Category::query()->offered()->orderBy('name')->get();
    }

    /**
     * Cities of the published providers, in alphabetical order.
     *
     * @return list<string>
     */
    public function cityOptions(): array
    {
        return $this->cityOptions ??= Provider::publishedCities();
    }

    /**
     * @param  array<string, mixed>  $failed
     */
    private function validFilter(string $key, array $failed): ?string
    {
        $value = $this->query($key);

        return is_string($value) && $value !== '' && ! array_key_exists($key, $failed) ? $value : null;
    }
}
