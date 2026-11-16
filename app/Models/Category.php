<?php

namespace App\Models;

use Database\Factories\CategoryFactory;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Str;

/**
 * @property bool|int|null $offered from withOffered() only: whether something can be booked in it
 */
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
        $query->whereHas('services', fn (Builder $services) => self::offeredServices($services));
    }

    /**
     * Every category, with "offered" (true or false) by the same rule as offered(), in the same
     * query: the home page shows them all, the offered ones as links (docs/home.md).
     *
     * @param  Builder<Category>  $query
     */
    #[Scope]
    protected function withOffered(Builder $query): void
    {
        $query->withExists(['services as offered' => fn (Builder $services) => self::offeredServices($services)]);
    }

    /**
     * The one rule of an offered category: an active service of a published provider.
     *
     * @param  Builder<Service>  $services
     */
    private static function offeredServices(Builder $services): void
    {
        $services->where('is_active', true)
            ->whereHas('provider', fn (Builder $provider) => $provider->where('is_published', true));
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
     * The key to put categories in alphabetical order by the name shown (#112): "Ongles" before
     * "Tresses" in French, "Braids" before "Nails" in English. Lower case and without accents
     * (Str::ascii, no intl extension in production), so "Énergie" sorts with the "e".
     */
    public function sortName(): string
    {
        return Str::lower(Str::ascii($this->displayName()));
    }

    /**
     * Whether a word of a search appears in the slug, in the saved name or in the name shown in
     * the language of the request, ignoring upper and lower case. Read in PHP, not with LIKE: the
     * shown name lives in the translation files, not in the database.
     */
    public function mentions(string $word): bool
    {
        foreach ([$this->slug, $this->name, $this->displayName()] as $text) {
            if (mb_stripos($text, $word) !== false) {
                return true;
            }
        }

        return false;
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
