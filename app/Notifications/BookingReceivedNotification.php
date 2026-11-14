<?php

namespace App\Notifications;

use App\Booking\LocalTime;
use App\Models\Booking;
use App\Models\ServiceOption;
use App\Support\Locales;
use App\Support\Money;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification as Notifications;
use Illuminate\Support\Facades\RateLimiter;
use Throwable;

/**
 * Tells the customer that the booking request has been sent, with the booking's private address.
 * The customer has no account: the email goes to the address written in the form, with an
 * on-demand notification. Mail only. Sent for the bookings of the public form only: never for the
 * ones the provider writes in the own agenda, nor for a request caught by the honeypot.
 *
 * As for the other emails, it is built from its own Blade views and never with MailMessage::line(),
 * which reads the text as Markdown: the customer's notes are printed as plain text. The private
 * address is in the body only, under a warning not to forward the email: never in the subject,
 * which holds the service name. The phone number is left out.
 *
 * The queued job carries the booking as its id only (SerializesModels): the public_token is read
 * again from the database when the email is built, so it is never written in the jobs table nor in
 * failed_jobs. The email address of the on-demand route is in the job, as for
 * BookingStatusNotification.
 */
class BookingReceivedNotification extends Notification implements ShouldQueue
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
     * Send the email to the customer, if the booking has an address; nothing otherwise. Called by
     * ProviderBookingController once the booking is saved and the provider's email is queued.
     *
     * At most config('booking.received_email_per_hour') emails of this kind per hour towards the
     * same address, whatever the IP (see limiterKey()). Over the limit the email is skipped and only
     * that fact is logged, without the address. A failure while queueing the email is reported and
     * nothing more: the booking stays valid. The email is in the customer's language (booking.locale).
     */
    public static function sendFor(Booking $booking): void
    {
        if ($booking->customer_email === null) {
            return;
        }

        try {
            $key = self::limiterKey($booking->customer_email);

            if (RateLimiter::tooManyAttempts($key, config()->integer('booking.received_email_per_hour'))) {
                Log::info('Booking received email skipped: hourly limit for one address reached.');

                return;
            }

            RateLimiter::hit($key, 3600);

            Notifications::route('mail', $booking->customer_email)
                ->notify((new self($booking))->locale(Locales::forDelivery($booking->locale)));
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    /**
     * The rate limiter's key for an address: an HMAC with the application key of the address in
     * lower case, so the address is never written in the cache in clear, and a plain hash cannot be
     * reversed by trying a list of known addresses. "Awa@Example.test" and "awa@example.test" are the
     * same mailbox for the limit.
     */
    public static function limiterKey(string $email): string
    {
        return 'booking-received-email:'.hash_hmac('sha256', mb_strtolower(trim($email)), config()->string('app.key'));
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

        $options = $booking->options
            ->sortBy('id')
            ->map(fn (ServiceOption $option) => $option->name)
            ->implode(', ');

        // Only the service name, written by the provider: nothing the customer wrote, and never the
        // private address, goes in a header.
        return (new MailMessage)
            ->subject(__('booking.received.subject', ['service' => $booking->service->name]))
            ->view(['html' => 'mail.booking-received', 'text' => 'mail.booking-received-text'], [
                'customerName' => $booking->customer_name,
                'providerName' => $booking->provider->name,
                'service' => $booking->service->name,
                'options' => $options === '' ? null : $options,
                // The provider's local time, in the language of the email (see LocalTime).
                'date' => LocalTime::longDate($booking->starts_at),
                'time' => LocalTime::timeRange($booking->starts_at, $booking->ends_at),
                // The total saved with the booking (issue #97), as on the private page.
                'price' => Money::format($booking->totalCents(), app()->getLocale()),
                'notes' => $booking->notes,
                // Absolute (APP_URL in the queue worker), read from the database by the worker.
                'url' => route('bookings.show', ['booking' => $booking->public_token]),
            ]);
    }
}
