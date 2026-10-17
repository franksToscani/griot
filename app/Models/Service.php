<?php

namespace App\Models;

use Database\Factories\ServiceFactory;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Service extends Model
{
    /** @use HasFactory<ServiceFactory> */
    use HasFactory;

    protected $fillable = [
        'category_id',
        'name',
        'description',
        'price',
        'duration_min',
        'duration_max',
        'is_active',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'duration_min' => 'integer',
            'duration_max' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Only services visible to customers.
     *
     * @param  Builder<Service>  $query
     */
    #[Scope]
    protected function active(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * Get the provider that offers the service.
     *
     * @return BelongsTo<Provider, $this>
     */
    public function provider(): BelongsTo
    {
        return $this->belongsTo(Provider::class);
    }

    /**
     * Get the category the service belongs to.
     *
     * @return BelongsTo<Category, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * Get the extra options available for the service.
     *
     * @return HasMany<ServiceOption, $this>
     */
    public function options(): HasMany
    {
        return $this->hasMany(ServiceOption::class);
    }

    /**
     * Get the bookings made for the service.
     *
     * @return HasMany<Booking, $this>
     */
    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }
}
