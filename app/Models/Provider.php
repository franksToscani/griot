<?php

namespace App\Models;

use Database\Factories\ProviderFactory;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Provider extends Model
{
    /** @use HasFactory<ProviderFactory> */
    use HasFactory;

    protected $fillable = [
        'slug',
        'name',
        'bio',
        'city',
        'whatsapp',
        'instagram',
        'avatar_path',
        'is_published',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_published' => 'boolean',
        ];
    }

    /**
     * Only providers visible to customers.
     *
     * @param  Builder<Provider>  $query
     */
    #[Scope]
    protected function published(Builder $query): void
    {
        $query->where('is_published', true);
    }

    /**
     * Only providers with at least one active service in the category with the given slug.
     *
     * @param  Builder<Provider>  $query
     */
    #[Scope]
    protected function inCategory(Builder $query, string $slug): void
    {
        $query->whereHas('services', fn (Builder $services) => $services
            ->where('is_active', true)
            ->whereHas('category', fn (Builder $category) => $category->where('slug', $slug)));
    }

    /**
     * Only providers in the given city.
     *
     * @param  Builder<Provider>  $query
     */
    #[Scope]
    protected function inCity(Builder $query, string $city): void
    {
        $query->where('city', $city);
    }

    /**
     * The distinct cities of the published providers, in alphabetical order.
     *
     * @return list<string>
     */
    public static function publishedCities(): array
    {
        return array_values(static::query()
            ->published()
            ->distinct()
            ->orderBy('city')
            ->get(['city'])
            ->map(fn (Provider $provider) => $provider->city)
            ->all());
    }

    /**
     * Get the user account that owns the provider.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the services offered by the provider.
     *
     * @return HasMany<Service, $this>
     */
    public function services(): HasMany
    {
        return $this->hasMany(Service::class);
    }

    /**
     * Get the weekly availabilities of the provider.
     *
     * @return HasMany<Availability, $this>
     */
    public function availabilities(): HasMany
    {
        return $this->hasMany(Availability::class);
    }

    /**
     * Get the bookings received by the provider.
     *
     * @return HasMany<Booking, $this>
     */
    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }
}
