<?php

namespace App\Models;

use App\Booking\CancelledBy;
use App\Enums\BookingStatus;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Database\Factories\BookingFactory;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A customer booking. starts_at and ends_at are stored in UTC.
 *
 * public_token is the secret code in the customer's private link: it is the only way the
 * customer finds the booking, so it must never appear in a Resource, a log or an error
 * message. It is not fillable: only the code sets it (CreateBooking, the factory, the seeder).
 * It is also hidden, so toArray() and toJson() leave it out if a model ever reaches a page or
 * a log by mistake; $booking->public_token and the route binding bookings/{booking:public_token}
 * still read it.
 *
 * status is not fillable either: CreateBooking sets it, and only BookingTransitions decides how it
 * changes (CancelBooking for the customer, ManageBooking for the provider). cancellation_reason is
 * set by ManageBooking only; cancelled_by (who cancelled, and how) by the one that cancels.
 * created_by_provider (the provider wrote it, no customer sent it) is not fillable: CreateBooking sets it.
 * reminder_sent_at (when the provider was reminded of a pending request) is not fillable either:
 * RemindPendingBookings writes it, once, with the query builder, so updated_at does not change.
 * locale (the customer's language, for the emails and the WhatsApp text) is not fillable either:
 * CreateBooking sets it, never from the input of the request.
 *
 * @property CarbonImmutable $starts_at
 * @property CarbonImmutable $ends_at
 * @property BookingStatus $status
 * @property string|null $cancellation_reason
 * @property CancelledBy|null $cancelled_by
 * @property bool $created_by_provider
 * @property CarbonImmutable|null $reminder_sent_at
 * @property string $locale
 */
class Booking extends Model
{
    /** @use HasFactory<BookingFactory> */
    use HasFactory;

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'public_token',
    ];

    protected $fillable = [
        'service_id',
        'customer_name',
        'customer_phone',
        'customer_email',
        'starts_at',
        'ends_at',
        'notes',
    ];

    /**
     * The model's default values for attributes.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => BookingStatus::Pending->value,
        'created_by_provider' => false,
        'locale' => 'it',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'status' => BookingStatus::class,
            'cancelled_by' => CancelledBy::class,
            'created_by_provider' => 'boolean',
            'reminder_sent_at' => 'datetime',
        ];
    }

    /**
     * Only bookings that keep the provider busy: pending or confirmed, never cancelled.
     *
     * @param  Builder<Booking>  $query
     */
    #[Scope]
    protected function occupying(Builder $query): void
    {
        $query->whereIn('status', [BookingStatus::Pending, BookingStatus::Confirmed]);
    }

    /**
     * Only bookings that overlap the span [$start, $end): a booking that ends exactly when
     * the span starts, or starts exactly when it ends, does not overlap.
     *
     * @param  Builder<Booking>  $query
     */
    #[Scope]
    protected function overlapping(Builder $query, CarbonInterface $start, CarbonInterface $end): void
    {
        // The dates are written in the query as they are, without a time zone: they are moved
        // to UTC first, like the stored values, so that a local date cannot shift the span.
        $query
            ->where('starts_at', '<', CarbonImmutable::instance($end)->utc())
            ->where('ends_at', '>', CarbonImmutable::instance($start)->utc());
    }

    /**
     * Only the pending requests to remind the provider of: created at or before $createdBefore, not
     * started yet, and never reminded. RemindPendingBookings reads them with this scope, and reads
     * each one again with it under the lock.
     *
     * @param  Builder<Booking>  $query
     */
    #[Scope]
    protected function awaitingReminder(Builder $query, CarbonInterface $createdBefore): void
    {
        // Moved to UTC first, like the stored values (see overlapping()).
        $query
            ->where('status', BookingStatus::Pending)
            ->whereNull('reminder_sent_at')
            ->where('created_at', '<=', CarbonImmutable::instance($createdBefore)->utc())
            ->where('starts_at', '>', CarbonImmutable::now()->utc());
    }

    /**
     * Get the provider the booking is for.
     *
     * @return BelongsTo<Provider, $this>
     */
    public function provider(): BelongsTo
    {
        return $this->belongsTo(Provider::class);
    }

    /**
     * Get the booked service.
     *
     * @return BelongsTo<Service, $this>
     */
    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    /**
     * Get the extra options chosen for the booking.
     *
     * @return BelongsToMany<ServiceOption, $this>
     */
    public function options(): BelongsToMany
    {
        return $this->belongsToMany(ServiceOption::class);
    }
}
