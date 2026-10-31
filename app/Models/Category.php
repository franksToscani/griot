<?php

namespace App\Models;

use Database\Factories\CategoryFactory;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Lang;

class Category extends Model
{
    /** @use HasFactory<CategoryFactory> */
    use HasFactory;

    protected $fillable = ['name', 'slug'];

    /**
     * Only categories with at least one active service of a published provider.
     *
     * @param  Builder<Category>  $query
     */
    #[Scope]
    protected function offered(Builder $query): void
    {
        $query->whereHas('services', fn (Builder $services) => $services
            ->where('is_active', true)
            ->whereHas('provider', fn (Builder $provider) => $provider->where('is_published', true)));
    }

    /**
     * The name to show, in the language of the request: the key "categories.{slug}" of the
     * translation files, or the saved name when the key is missing (a category added later).
     */
    public function displayName(): string
    {
        $key = "categories.{$this->slug}";

        return Lang::has($key) ? __($key) : $this->name;
    }

    /**
     * Get the services in the category.
     *
     * @return HasMany<Service, $this>
     */
    public function services(): HasMany
    {
        return $this->hasMany(Service::class);
    }
}
