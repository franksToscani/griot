import { Form, Link } from '@inertiajs/react';
import { useRef, useState } from 'react';
import InputError from '@/components/input-error';
import { NewTabHint } from '@/components/new-tab-hint';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import { PauseLiveProps } from '@/hooks/use-live-props';
import { useTranslation } from '@/hooks/use-translation';
import { formatCents, formatLongDate } from '@/lib/format';
import { touchTarget } from '@/lib/touch-target';
import { cn } from '@/lib/utils';
// Explicit names: '@/routes/bookings' has the customer's own "cancel".
import {
    cancel as cancelBooking,
    confirm as confirmBooking,
    reject as rejectBooking,
    show as showBooking,
} from '@/routes/provider/bookings';
import type { ProviderBooking } from '@/types';

// A booking of the provider, with its answers: the card of the bookings list and of the booking's
// own page (pages/provider/bookings/index.tsx and show.tsx).

// Each status with its Badge: the pending ones stand out, they wait for an answer.
const statusBadge = {
    pending: 'default',
    confirmed: 'secondary',
    cancelled: 'outline',
} as const;

// Copied from dashboard.tsx, like the other pages: the kit's Button keeps its label on one line at
// a fixed height, and long Italian labels would widen the page at 375 px. cn() uses tailwind-merge.
const wrappingLabel = 'h-auto max-w-full whitespace-normal py-2 text-center';

// The stable places for the focus after an answer: the title of the booking's card, or the title of
// the page when the card left the list (a filter on the status). Both have tabIndex={-1}. Both
// pages give their h1 this id.
export const PAGE_TITLE_ID = 'provider-bookings-title';
const cardTitleId = (bookingId: number): string =>
    `provider-booking-${bookingId}-title`;

// After a confirmation, a rejection or a cancellation the button the focus was on is gone: the
// focus goes back to the card, on the next frame (the new page is there by then). The result
// itself is the toast, read by Sonner's live region (aria-live="polite").
function focusAfterAnswer(bookingId: number): void {
    requestAnimationFrame(() => {
        (
            document.getElementById(cardTitleId(bookingId)) ??
            document.getElementById(PAGE_TITLE_ID)
        )?.focus();
    });
}

// On the booking's own page every answer says so in the hidden field "return": the server then
// comes back to that page (App\Http\ProviderBookingReturn), the list otherwise.
function ReturnField({ onDetailPage }: { onDetailPage: boolean }) {
    return onDetailPage ? (
        <input type="hidden" name="return" value="detail" />
    ) : null;
}

// One booking. Everything the customer wrote is shown as plain text: no links, no HTML.
// On the list the date is a link to the booking's page; on that page it is plain text.
export function BookingCard({
    booking,
    onDetailPage = false,
}: {
    booking: ProviderBooking;
    onDetailPage?: boolean;
}) {
    const { t, locale } = useTranslation();
    const pending = booking.status === 'pending';
    // The date is already the provider's local day (see formatLongDate).
    const date = formatLongDate(locale, booking.local_date);

    return (
        <Card className={cn(pending && 'border-2 border-primary')}>
            <CardHeader>
                <CardTitle className="flex flex-wrap items-center gap-2">
                    {/* A heading per booking: the focus comes back here after an answer, and
                        screen readers can move from booking to booking. */}
                    <h2
                        id={cardTitleId(booking.id)}
                        tabIndex={-1}
                        className="min-w-0 break-words"
                    >
                        {onDetailPage ? (
                            date
                        ) : (
                            <Link
                                href={showBooking(booking.id)}
                                className={cn(
                                    'underline underline-offset-4',
                                    touchTarget,
                                )}
                            >
                                {date}
                            </Link>
                        )}
                    </h2>
                    <Badge variant={statusBadge[booking.status]}>
                        {t(`provider.bookings.status.${booking.status}`)}
                    </Badge>
                </CardTitle>
                <CardDescription className="tabular-nums">
                    {booking.local_start} – {booking.local_end}
                </CardDescription>
            </CardHeader>
            <CardContent className="flex flex-col gap-3 text-sm">
                <div className="flex flex-col gap-1">
                    {/* An anonymous booking keeps no name and no phone: a translated label only. */}
                    {booking.anonymized ? (
                        <p className="text-muted-foreground italic">
                            {t('provider.bookings.anonymous_customer')}
                        </p>
                    ) : (
                        <p className="font-medium break-words">
                            {booking.customer_name}
                        </p>
                    )}
                    {booking.customer_phone !== null && (
                        <p className="break-words">
                            {t('provider.bookings.phone')}:{' '}
                            {booking.customer_phone}
                        </p>
                    )}
                    {booking.customer_email !== null && (
                        <p className="break-all">
                            {t('provider.bookings.email')}:{' '}
                            {booking.customer_email}
                        </p>
                    )}
                    {booking.notes !== null && (
                        <div>
                            <p className="font-medium">
                                {t('provider.bookings.notes')}
                            </p>
                            <p className="break-words whitespace-pre-line">
                                {booking.notes}
                            </p>
                        </div>
                    )}
                </div>

                <div className="flex flex-col gap-1">
                    <p className="font-medium break-words">
                        {booking.service.name}
                    </p>
                    {booking.options.length > 0 && (
                        <ul className="list-disc pl-5">
                            {booking.options.map((option, position) => (
                                <li key={position} className="break-words">
                                    {option.name}
                                    {/* Only an extra that actually adds something. */}
                                    {option.extra_price_cents !== 0 &&
                                        ` (${t('providers.show.option_extra_price', { price: formatCents(locale, option.extra_price_cents) })})`}
                                </li>
                            ))}
                        </ul>
                    )}
                </div>

                <p className="font-medium">
                    {t('provider.bookings.saved_price', {
                        price: formatCents(locale, booking.price_cents),
                    })}
                </p>

                {/* Only the actions the server allows now: after an answer the flags come back false. */}
                {(booking.can_confirm ||
                    booking.can_reject ||
                    booking.can_cancel) && (
                    <div className="flex flex-wrap gap-2">
                        {booking.can_confirm && (
                            <ConfirmBookingButton
                                bookingId={booking.id}
                                onDetailPage={onDetailPage}
                            />
                        )}
                        {booking.can_reject && (
                            <ReasonDialog
                                bookingId={booking.id}
                                action={rejectBooking.form({
                                    booking: booking.id,
                                })}
                                idPrefix={`reject-${booking.id}`}
                                onDetailPage={onDetailPage}
                                trigger={t('provider.bookings.actions.reject')}
                                title={t(
                                    'provider.bookings.actions.reject_title',
                                )}
                                description={t(
                                    'provider.bookings.actions.reject_description',
                                )}
                                yes={t(
                                    'provider.bookings.actions.confirm_yes_reject',
                                )}
                            />
                        )}
                        {booking.can_cancel && (
                            <ReasonDialog
                                bookingId={booking.id}
                                action={cancelBooking.form({
                                    booking: booking.id,
                                })}
                                idPrefix={`cancel-${booking.id}`}
                                onDetailPage={onDetailPage}
                                trigger={t('provider.bookings.actions.cancel')}
                                title={t(
                                    'provider.bookings.actions.cancel_title',
                                )}
                                description={t(
                                    'provider.bookings.actions.cancel_description',
                                )}
                                yes={t(
                                    'provider.bookings.actions.confirm_yes_cancel',
                                )}
                            />
                        )}
                    </div>
                )}

                {/* How the customer hears of the answer. The outcome comes from the Resource
                    (BookingOutcome): null for a pending booking, one cancelled by the customer
                    (which sends no email) and an old cancelled one. A booking the provider wrote
                    is born confirmed with no email: the email line waits for a later answer (a
                    cancellation does send one). Known limit: a booking confirmed before #102 has
                    an outcome but got no email, so for those data the email line can be false.
                    "Sent" means queued: the email leaves later. */}
                {booking.whatsapp_url !== null && (
                    <div>
                        <Button
                            variant="outline"
                            size="sm"
                            asChild
                            className={wrappingLabel}
                        >
                            <a
                                href={booking.whatsapp_url}
                                target="_blank"
                                rel="noopener noreferrer"
                            >
                                {t('provider.bookings.whatsapp_button')}
                                <NewTabHint />
                            </a>
                        </Button>
                    </div>
                )}
                {booking.outcome !== null &&
                    booking.customer_email !== null &&
                    !(
                        booking.created_by_provider &&
                        booking.outcome === 'confirmed'
                    ) && (
                        <p className="break-all text-muted-foreground">
                            {t('provider.bookings.email_notice', {
                                email: booking.customer_email,
                            })}
                        </p>
                    )}
                {!booking.anonymized &&
                    booking.outcome !== null &&
                    booking.whatsapp_url === null &&
                    booking.customer_email === null && (
                        <p className="text-muted-foreground">
                            {t('provider.bookings.no_contact')}
                        </p>
                    )}
            </CardContent>
        </Card>
    );
}

// Confirming needs no window: a refusal (for example the customer cancelled in the meantime) is
// shown at the top of the page.
function ConfirmBookingButton({
    bookingId,
    onDetailPage,
}: {
    bookingId: number;
    onDetailPage: boolean;
}) {
    const { t } = useTranslation();

    return (
        <Form
            {...confirmBooking.form({ booking: bookingId })}
            options={{ preserveScroll: true }}
            onSuccess={() => focusAfterAnswer(bookingId)}
        >
            {({ processing }) => (
                <>
                    <PauseLiveProps when={processing} />
                    <ReturnField onDetailPage={onDetailPage} />
                    <Button
                        type="submit"
                        size="sm"
                        className={wrappingLabel}
                        disabled={processing}
                    >
                        {processing && <Spinner />}
                        {t('provider.bookings.actions.confirm')}
                    </Button>
                </>
            )}
        </Form>
    );
}

// Reject or cancel, with an optional message the customer will read on the private page. A
// success closes the window. An error on the message keeps it open, with the error and the text
// written; any other error (a refused change, the request limit) closes it, and is shown at the
// top of the page.
function ReasonDialog({
    bookingId,
    action,
    idPrefix,
    onDetailPage,
    trigger,
    title,
    description,
    yes,
}: {
    bookingId: number;
    action: ReturnType<typeof rejectBooking.form>;
    idPrefix: string;
    onDetailPage: boolean;
    trigger: string;
    title: string;
    description: string;
    yes: string;
}) {
    const { t } = useTranslation();
    const [open, setOpen] = useState(false);
    // Set by a success: the window then gives the focus to the card, not to its trigger, which the
    // answer has removed. After an error the trigger is still there: Radix gives the focus back.
    const answered = useRef(false);

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            {/* No new state while the window is open: it could remove the window and the text. */}
            <PauseLiveProps when={open} />
            <DialogTrigger asChild>
                <Button
                    variant="destructive"
                    size="sm"
                    className={wrappingLabel}
                >
                    {trigger}
                </Button>
            </DialogTrigger>
            <DialogContent
                onCloseAutoFocus={(event) => {
                    if (answered.current) {
                        answered.current = false;
                        event.preventDefault();
                        focusAfterAnswer(bookingId);
                    }
                }}
            >
                <DialogHeader>
                    <DialogTitle>{title}</DialogTitle>
                    <DialogDescription>{description}</DialogDescription>
                </DialogHeader>
                <Form
                    {...action}
                    options={{ preserveScroll: true }}
                    onSuccess={() => {
                        answered.current = true;
                        setOpen(false);
                    }}
                    onError={(errors) => {
                        if (!errors.reason) {
                            setOpen(false);
                        }
                    }}
                    className="flex flex-col gap-4"
                >
                    {({ processing, errors }) => (
                        <>
                            <PauseLiveProps when={processing} />
                            <ReturnField onDetailPage={onDetailPage} />
                            <div className="grid gap-2">
                                <Label htmlFor={`${idPrefix}-reason`}>
                                    {t(
                                        'provider.bookings.actions.reason_label',
                                    )}
                                </Label>
                                <Textarea
                                    id={`${idPrefix}-reason`}
                                    name="reason"
                                    maxLength={500}
                                    aria-describedby={`${idPrefix}-reason-help`}
                                />
                                <p
                                    id={`${idPrefix}-reason-help`}
                                    className="text-sm text-muted-foreground"
                                >
                                    {t('provider.bookings.actions.reason_help')}
                                </p>
                                <InputError message={errors.reason} />
                            </div>
                            <DialogFooter className="gap-2">
                                <DialogClose asChild>
                                    <Button
                                        type="button"
                                        variant="secondary"
                                        className={wrappingLabel}
                                    >
                                        {t('provider.bookings.actions.back')}
                                    </Button>
                                </DialogClose>
                                <Button
                                    type="submit"
                                    variant="destructive"
                                    className={wrappingLabel}
                                    disabled={processing}
                                >
                                    {processing && <Spinner />}
                                    {yes}
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}
