<?php

namespace App\Notifications;

use App\Booking\LocalTime;
use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\ServiceOption;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Reminds the provider's user of a request still pending after booking.reminder_after_minutes.
 * Mail only, as the other notices to the provider. Queued by RemindPendingBookings, which writes
 * reminder_sent_at in the same transaction.
 *
 * The email is built from its own Blade views, never with MailMessage::line(), which reads the
 * text as Markdown. The customer's notes and email, the public_token and the private address are
 * left out on purpose: the provider needs to know which request waits, and where to answer it.
 */
class PendingReminderNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * Attempts before the job goes to the failed jobs table.
     */
    public int $tries = 3;

    /**
     * If the booking no longer exists when the job runs, the job is deleted without failing.
     */
    public bool $deleteWhenMissingModels = true;

    public function __construct(
        public Booking $booking,
    ) {}

    /**
     * Seconds to wait before the second and the third attempt.
     *
     * @return list<int>
     */
    public function backoff(): array
    {
        return [60, 300];
    }

    /**
     * Get the notification's delivery channels.
     *
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /**
     * Checked by the worker right before sending: the provider may have answered, or the customer
     * cancelled, while the job waited in the queue. The status is read again from the database.
     */
    public function shouldSend(object $notifiable, string $channel): bool
    {
        return $this->booking->fresh()?->status === BookingStatus::Pending;
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        // One query per relation, however many options were chosen.
        $booking = $this->booking->loadMissing(['provider', 'service', 'options']);

        $options = $booking->options
            ->sortBy('id')
            ->map(fn (ServiceOption $option) => $option->name)
            ->implode(', ');

        // Only the service name, written by the provider: nothing the customer wrote goes in a header.
        return (new MailMessage)
            ->subject(__('booking.reminder.subject', ['service' => $booking->service->name]))
            ->view(['html' => 'mail.pending-reminder', 'text' => 'mail.pending-reminder-text'], [
                'providerName' => $booking->provider->name,
                // The same threshold that chose the booking, in whole hours.
                'hours' => intdiv(config()->integer('booking.reminder_after_minutes'), 60),
                'service' => $booking->service->name,
                'options' => $options === '' ? null : $options,
                // For example "giovedì 29 ottobre 2026" and "09:00–11:45", as in the other emails.
                'date' => LocalTime::longDate($booking->starts_at),
                'time' => LocalTime::timeRange($booking->starts_at, $booking->ends_at),
                'customerName' => $booking->customer_name,
                'customerPhone' => $booking->customer_phone,
                // The provider's own list, behind the login: never the customer's private address.
                'listUrl' => route('provider.bookings.index', ['status' => BookingStatus::Pending->value]),
            ]);
    }
}
