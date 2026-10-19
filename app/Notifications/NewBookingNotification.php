<?php

namespace App\Notifications;

use App\Models\Booking;
use App\Models\ServiceOption;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells the provider's user that a customer has sent a booking request. Mail only: a database
 * channel would copy the customer's personal data into another table.
 *
 * The email is built from its own Blade views, not with MailMessage::line(): line() reads the
 * text as Markdown, so a customer could turn the name or the notes into links or headings.
 * The views print every customer field as plain text. The public_token, the private address
 * and the price are left out on purpose.
 */
class NewBookingNotification extends Notification implements ShouldQueue
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
     * Get the mail representation of the notification.
     */
    public function toMail(object $notifiable): MailMessage
    {
        // One query per relation, however many options were chosen.
        $booking = $this->booking->loadMissing(['provider', 'service', 'options']);

        // The provider's local time, the same rule as BookingConfirmationResource: neither the
        // server's nor the browser's time zone matters. Carbon follows the application's locale,
        // so isoFormat() writes day and month in that language.
        $timezone = config()->string('booking.timezone');
        $start = $booking->starts_at->setTimezone($timezone);
        $end = $booking->ends_at->setTimezone($timezone);

        $options = $booking->options
            ->sortBy('id')
            ->map(fn (ServiceOption $option) => $option->name)
            ->implode(', ');

        // Only the service name, written by the provider: nothing the customer wrote goes in a header.
        return (new MailMessage)
            ->subject(__('booking.notification.subject', ['service' => $booking->service->name]))
            ->view(['html' => 'mail.new-booking', 'text' => 'mail.new-booking-text'], [
                'providerName' => $booking->provider->name,
                'service' => $booking->service->name,
                'options' => $options === '' ? null : $options,
                // For example "giovedì 29 ottobre 2026" and "09:00–11:45".
                'date' => $start->isoFormat('dddd D MMMM YYYY'),
                'time' => $start->format('H:i').'–'.$end->format('H:i'),
                'customerName' => $booking->customer_name,
                'customerPhone' => $booking->customer_phone,
                'customerEmail' => $booking->customer_email,
                'notes' => $booking->notes,
            ]);
    }
}
