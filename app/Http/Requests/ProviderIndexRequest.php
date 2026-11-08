<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\DecimalPrice;
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
    use DecimalPrice;

    /**
     * The filters of the list, in one place (docs/providers-list.md): filters() reads them, the
     * links of the pagination keep them, and the page receives them (filterKeys) for "Azzera
     * filtri", the hidden fields of the search and the count of "Filtri (n)". The order (sort) is
     * not a filter.
     */
    public const FILTERS = ['category', 'city', 'q', 'max_price', 'with_photo'];

    /**
     * The filters of the panel, counted by its button "Filtri (n)": not the city nor the search,
     * which are in the search bar.
     */
    public const PANEL_FILTERS = ['category', 'max_price', 'with_photo'];

    /**
     * The longest search, in characters: a longer one is ignored, like an invalid filter.
     */
    public const SEARCH_MAX = 100;

    /**
     * The orders of the list (docs/search.md): name A→Z (the default, when sort is missing),
     * the most recently published first, the lowest price ("da X €") up or down.
     */
    public const SORTS = ['name', 'recent', 'price_asc', 'price_desc'];

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
     * "39,90" is read as "39.90", as for the price of a service.
     */
    protected function prepareForValidation(): void
    {
        $this->commaToDot('max_price', 4);
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
            // The text of the search (scope Provider::search()). Already trimmed by TrimStrings.
            'q' => ['nullable', 'string', 'max:'.self::SEARCH_MAX],
            'sort' => ['nullable', 'string', Rule::in(self::SORTS)],
            // Euros, positive, at most 9999.99 (the largest price of a service).
            'max_price' => ['nullable', 'string', 'regex:/^\d{1,4}(\.\d{1,2})?$/', 'not_regex:/^0*(\.0*)?$/'],
            // Only "1" turns it on.
            'with_photo' => ['nullable', 'string', Rule::in(['1'])],
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
     * The valid filters (FILTERS) and the order; an invalid or missing one is null (for sort:
     * the default order, by name). The maximum price comes as "39.90".
     *
     * The keys are those of FILTERS, then sort, in this order (ProviderListFiltersTest).
     *
     * @return array<string, string|null>
     */
    public function filters(): array
    {
        $failed = $this->getValidatorInstance()->failed();
        $filters = [];

        foreach ([...self::FILTERS, 'sort'] as $key) {
            $filters[$key] = $this->validFilter($key, $failed);
        }

        if ($filters['max_price'] !== null) {
            $filters['max_price'] = $this->normalizedPrice($filters['max_price']);
        }

        return $filters;
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
        $value = is_string($value) ? trim($value) : $value;

        return is_string($value) && $value !== '' && ! array_key_exists($key, $failed) ? $value : null;
    }
}
