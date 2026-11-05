<?php

namespace App\Models;

use App\Support\MediaDisk;
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

    /**
     * Only the profile fields a provider writes. slug (unique), is_published (issue #37) with its
     * published_at (issue #144) and avatar_path (issue #36) are always set by explicit code, never
     * by mass assignment.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'city',
        'bio',
        'whatsapp',
        'instagram',
    ];

    /**
     * Never in a response: published_at only sorts (issue #144). The Resources list their fields
     * one by one; this covers the model serialized whole, as auth.user with its provider loaded.
     *
     * @var list<string>
     */
    protected $hidden = [
        'published_at',
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
            'published_at' => 'datetime',
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
     * Load what the public page shows: active services with category and options, the weekly
     * availabilities and the photos of the work, in a fixed order and with one query per relation.
     *
     * @param  Builder<Provider>  $query
     */
    #[Scope]
    protected function withPublicProfile(Builder $query): void
    {
        $query->with([
            'services' => fn ($services) => $services->active()->orderBy('name'),
            'services.category',
            // Only the options offered to new customers; the bookings keep the hidden ones.
            'services.options' => fn ($options) => $options->active()->orderBy('id'),
            'availabilities' => fn ($availabilities) => $availabilities->orderBy('weekday')->orderBy('start_time'),
            // The photos of the work (#143), in the order of the relation: position, then id.
            'photos',
        ]);
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
     * Public URL of the avatar, or null when there is none.
     */
    public function avatarUrl(): ?string
    {
        return $this->avatar_path !== null
            ? MediaDisk::get()->url($this->avatar_path)
            : null;
    }

    /**
     * WhatsApp chat link, only for a number in international format (+39… or 0039…).
     * Any other value gets no link: the country code is never guessed.
     */
    public function whatsappUrl(): ?string
    {
        $number = trim($this->whatsapp ?? '');

        if (! str_starts_with($number, '+') && ! str_starts_with($number, '00')) {
            return null;
        }

        // wa.me wants the country code without "+" or "00".
        $digits = preg_replace('/\D/', '', $number) ?? '';
        $digits = str_starts_with($number, '00') ? substr($digits, 2) : $digits;

        return $digits !== '' ? 'https://wa.me/'.$digits : null;
    }

    /**
     * Instagram profile link, only for a username made of letters, digits, dots and underscores.
     */
    public function instagramUrl(): ?string
    {
        $username = $this->instagramUsername();

        return $username !== null && preg_match('/^[A-Za-z0-9._]+$/', $username) === 1
            ? 'https://instagram.com/'.$username
            : null;
    }

    /**
     * The Instagram username without one leading "@", or null when there is none.
     */
    public function instagramUsername(): ?string
    {
        $username = trim($this->instagram ?? '');
        $username = str_starts_with($username, '@') ? substr($username, 1) : $username;

        return $username !== '' ? $username : null;
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
     * Get the photos of the provider's work (issue #143), in their order: position, then id.
     *
     * @return HasMany<ProviderPhoto, $this>
     */
    public function photos(): HasMany
    {
        return $this->hasMany(ProviderPhoto::class)->orderBy('position')->orderBy('id');
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
