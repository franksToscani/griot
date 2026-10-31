<?php

namespace App\Notifications;

use App\Booking\BookingOutcome;
use App\Booking\LocalTime;
use App\Models\Booking;
use App\Support\Locales;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Notification as Notifications;
use Throwable;

/**
 * Tells the customer the provider's answer: confirmed, rejected or cancelled. The customer has no
 * account: the email goes to the address written in the form, with an on-demand notification.
 * Mail only.
 *
 * As for NewBookingNotification, the email is built from its own Blade views and never with
 * MailMessage::line(), which reads the text as Markdown. The private address of the booking is
 * in the body only: never in the subject, which holds the service name and the outcome.
 */
class BookingStatusNotification extends Notification implements ShouldQueue
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

    /**
     * The outcome is the one of the moment the provider answered: the job runs later, when the
     * booking may have changed again (a confirmed booking cancelled by the customer).
     */
    public function __construct(
        public Booking $booking,
        public BookingOutcome $outcome,
    ) {}

    /**
     * Send the email to the customer, if the booking has an address; nothing otherwise. Called by
     * the controllers once the provider's action is saved, outside its transaction. A failure
     * while queueing the email is reported and nothing more: the action stays done. The email is in
     * the customer's language (booking.locale), whatever the language of the provider who answered:
     * the job carries it to the worker.
     */
    public static function sendFor(Booking $booking, BookingOutcome $outcome): void
    {
        if ($booking->customer_email === null) {
            return;
        }

        try {
            Notifications::route('mail', $booking->customer_email)
                ->notify((new self($booking, $outcome))->locale(Locales::forDelivery($booking->locale)));
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
        $booking = $this->booking->loadMissing(['provider', 'service']);

        // Only the service name, written by the provider, and the outcome: nothing the customer
        // wrote, and never the private address, goes in a header.
        return (new MailMessage)
            ->subject(__('booking.notice.subject_'.$this->outcome->value, ['service' => $booking->service->name]))
            ->view(['html' => 'mail.booking-status', 'text' => 'mail.booking-status-text'], [
                'customerName' => $booking->customer_name,
                'outcomeText' => __('booking.notice.'.$this->outcome->value, ['provider' => $booking->provider->name]),
                'service' => $booking->service->name,
                // For example "giovedì 29 ottobre 2026" and "09:00–11:45", as in the provider's email.
                'date' => LocalTime::longDate($booking->starts_at),
                'time' => LocalTime::timeRange($booking->starts_at, $booking->ends_at),
                // The provider's message, only for a rejection or a cancellation.
                'reason' => $this->outcome === BookingOutcome::Confirmed ? null : $booking->cancellation_reason,
                'url' => route('bookings.show', ['booking' => $booking->public_token]),
            ]);
    }
}
