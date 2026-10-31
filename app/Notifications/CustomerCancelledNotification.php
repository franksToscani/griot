<?php

namespace App\Notifications;

use App\Booking\LocalTime;
use App\Models\Booking;
use App\Models\ServiceOption;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Throwable;

/**
 * Tells the provider's user that a customer has cancelled a booking from the private page. Mail
 * only, as NewBookingNotification.
 *
 * The email is built from its own Blade views, never with MailMessage::line(), which reads the
 * text as Markdown. The customer's notes, the public_token and the private address are left out
 * on purpose: the provider only needs to know which slot is free again, and who had it.
 */
class CustomerCancelledNotification extends Notification implements ShouldQueue
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
     * Send the email to the provider's user. Called by the controller only when the cancellation
     * happened, outside its transaction. A failure while queueing the email is reported and
     * nothing more: the booking stays cancelled.
     */
    public static function sendFor(Booking $booking): void
    {
        try {
            $booking->provider->user->notify(new self($booking));
        } catch (Throwable $exception) {
            report($exception);
        }
    }

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
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        // One query per relation, however many options were chosen.
        $booking = $this->booking->loadMissing(['provider', 'service', 'options']);
        $start = LocalTime::fromUtc($booking->starts_at);
        $end = LocalTime::fromUtc($booking->ends_at);

        $options = $booking->options
            ->sortBy('id')
            ->map(fn (ServiceOption $option) => $option->name)
            ->implode(', ');

        // Only the service name, written by the provider: nothing the customer wrote goes in a header.
        return (new MailMessage)
            ->subject(__('booking.cancelled_notice.subject', ['service' => $booking->service->name]))
            ->view(['html' => 'mail.customer-cancelled', 'text' => 'mail.customer-cancelled-text'], [
                'providerName' => $booking->provider->name,
                'service' => $booking->service->name,
                'options' => $options === '' ? null : $options,
                // For example "giovedì 29 ottobre 2026" and "09:00–11:45", as in the other emails.
                'date' => $start->isoFormat('dddd D MMMM YYYY'),
                'time' => $start->format('H:i').'–'.$end->format('H:i'),
                'customerName' => $booking->customer_name,
                'customerPhone' => $booking->customer_phone,
            ]);
    }
}
