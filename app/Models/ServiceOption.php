<?php

namespace App\Models;

use Database\Factories\ServiceOptionFactory;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * @property string $extra_price decimal string such as "7.50" (cast decimal:2)
 */
class ServiceOption extends Model
{
    /** @use HasFactory<ServiceOptionFactory> */
    use HasFactory;

    protected $fillable = ['name', 'extra_price', 'extra_duration'];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'extra_price' => 'decimal:2',
            'extra_duration' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /**
     * Only options offered to new customers. A hidden option stays in the bookings that already
     * chose it. is_active is not fillable: it changes only through ManageOption.
     *
     * @param  Builder<ServiceOption>  $query
     */
    #[Scope]
    protected function active(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * Get the service the option belongs to.
     *
     * @return BelongsTo<Service, $this>
     */
    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    /**
     * Get the bookings in which the option was chosen.
     *
     * @return BelongsToMany<Booking, $this>
     */
    public function bookings(): BelongsToMany
    {
        return $this->belongsToMany(Booking::class);
    }
}
