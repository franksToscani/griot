<?php

namespace App\Booking;

use App\Models\Booking;
use RuntimeException;

/**
 * The provider asked for a change of status that the rules do not allow, for example confirming a
 * booking the customer has just cancelled. It happens in normal use; the request turns reason()
 * into a translated message. The technical message never contains the public_token or personal
 * data.
 */
final class TransitionNotAllowed extends RuntimeException
{
    /**
     * @param  'not_pending'|'not_confirmed'|'already_started'|'already_cancelled'|'too_late'  $reason
     */
    private function __construct(string $message, private readonly string $reason)
    {
        parent::__construct($message);
    }

    /**
     * @param  'confirm'|'reject'|'cancel'  $action
     * @param  'not_pending'|'not_confirmed'|'already_started'|'already_cancelled'|'too_late'  $reason
     */
    public static function because(Booking $booking, string $action, string $reason): self
    {
        return new self(sprintf(
            'Booking %d (%s) cannot be changed by "%s": %s.',
            $booking->id,
            $booking->status->value,
            $action,
            $reason,
        ), $reason);
    }

    /**
     * Why the change is refused.
     *
     * @return 'not_pending'|'not_confirmed'|'already_started'|'already_cancelled'|'too_late'
     */
    public function reason(): string
    {
        return $this->reason;
    }
}
