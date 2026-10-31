import { Form, Head, Link, usePage } from '@inertiajs/react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import { Pagination } from '@/components/pagination';
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
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import { useTranslation } from '@/hooks/use-translation';
import { centsToPrice, formatPrice, priceToCents } from '@/lib/format';
import { cn } from '@/lib/utils';
import { dashboard } from '@/routes';
// Explicit names: '@/routes/bookings' has the customer's own "cancel".
import {
    cancel as cancelBooking,
    confirm as confirmBooking,
    index as bookingsIndex,
    reject as rejectBooking,
} from '@/routes/provider/bookings';
import type {
    Paginated,
    ProviderBooking,
    ProviderBookingFilters,
} from '@/types';

type Props = {
    bookings: Paginated<ProviderBooking>;
    filters: ProviderBookingFilters;
};

const STATUSES = ['pending', 'confirmed', 'cancelled'] as const;

// Each status with its Badge: the pending ones stand out, they wait for an answer.
const statusBadge = {
    pending: 'default',
    confirmed: 'secondary',
    cancelled: 'outline',
} as const;

// Copied from dashboard.tsx, like the other pages: the kit's Button keeps its label on one line at
// a fixed height, and long Italian labels would widen the page at 375 px. cn() uses tailwind-merge.
const wrappingLabel = 'h-auto max-w-full whitespace-normal py-2 text-center';

// Copied from services/index.tsx: a native select with the same look as the kit's Input.
const selectClass =
    'border-input flex h-9 w-full min-w-0 rounded-md border bg-transparent px-3 py-1 text-base shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 aria-invalid:border-destructive md:text-sm';

const hasActiveFilters = (filters: ProviderBookingFilters): boolean =>
    filters.status !== null || filters.from !== null || filters.to !== null;

// The provider's bookings, read only, in the order the server sends: the pending ones first.
export default function ProviderBookingsIndex({ bookings, filters }: Props) {
    const { t } = useTranslation();
    const filtered = hasActiveFilters(filters);
    const { errors } = usePage().props;

    return (
        <>
            <Head title={t('provider.bookings.title')} />
            <div className="flex flex-col gap-6 p-4">
                <header className="flex max-w-2xl flex-col gap-1">
                    <h1 className="text-2xl font-semibold">
                        {t('provider.bookings.title')}
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        {filtered
                            ? t('provider.bookings.filters_active')
                            : t('provider.bookings.help')}
                    </p>
                </header>

                {/* Read from the page, outside the forms: a refused change, or the request limit. */}
                <div className="max-w-2xl">
                    <InputError message={errors.booking} />
                    <InputError message={errors.services} />
                </div>

                <BookingFilters filters={filters} filtered={filtered} />

                {bookings.data.length === 0 ? (
                    <p className="text-muted-foreground">
                        {filtered
                            ? t('provider.bookings.empty_filtered')
                            : t('provider.bookings.empty')}
                    </p>
                ) : (
                    <ul className="flex max-w-2xl flex-col gap-4">
                        {bookings.data.map((booking) => (
                            <li key={booking.id}>
                                <BookingCard booking={booking} />
                            </li>
                        ))}
                    </ul>
                )}

                <Pagination links={bookings.links} meta={bookings.meta} />
            </div>
        </>
    );
}

// A GET form: the fields go in the query string. Empty fields are left out of the address. A GET
// visit does not keep the page state, so the fields start again from "filters" after each visit,
// and the errors are read from the page: an invalid filter comes back to the list without filters.
function BookingFilters({
    filters,
    filtered,
}: {
    filters: ProviderBookingFilters;
    filtered: boolean;
}) {
    const { t } = useTranslation();
    const { errors } = usePage().props;

    return (
        <Card className="max-w-2xl">
            <CardContent>
                <Form
                    {...bookingsIndex.form()}
                    transform={(data) =>
                        Object.fromEntries(
                            Object.entries(data).filter(
                                ([, value]) => value !== '',
                            ),
                        )
                    }
                    options={{ preserveScroll: true }}
                    className="flex flex-col gap-4"
                >
                    <div className="grid gap-4 sm:grid-cols-3">
                        <div className="grid gap-2">
                            <Label htmlFor="filter-status">
                                {t('provider.bookings.filter.status')}
                            </Label>
                            <select
                                id="filter-status"
                                name="status"
                                defaultValue={filters.status ?? ''}
                                className={selectClass}
                            >
                                <option value="">
                                    {t('provider.bookings.filter.status_all')}
                                </option>
                                {STATUSES.map((status) => (
                                    <option key={status} value={status}>
                                        {t(
                                            `provider.bookings.status.${status}`,
                                        )}
                                    </option>
                                ))}
                            </select>
                            <InputError message={errors.status} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="filter-from">
                                {t('provider.bookings.filter.from')}
                            </Label>
                            <Input
                                id="filter-from"
                                name="from"
                                type="date"
                                defaultValue={filters.from ?? ''}
                            />
                            <InputError message={errors.from} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="filter-to">
                                {t('provider.bookings.filter.to')}
                            </Label>
                            <Input
                                id="filter-to"
                                name="to"
                                type="date"
                                defaultValue={filters.to ?? ''}
                            />
                            <InputError message={errors.to} />
                        </div>
                    </div>

                    <div className="flex flex-wrap gap-2">
                        <Button type="submit" className={wrappingLabel}>
                            {t('provider.bookings.filter.submit')}
                        </Button>
                        {filtered && (
                            <Button
                                variant="outline"
                                asChild
                                className={wrappingLabel}
                            >
                                <Link href={bookingsIndex()} preserveScroll>
                                    {t('provider.bookings.filter.reset')}
                                </Link>
                            </Button>
                        )}
                    </div>
                </Form>
            </CardContent>
        </Card>
    );
}

// One booking. Everything the customer wrote is shown as plain text: no links, no HTML.
function BookingCard({ booking }: { booking: ProviderBooking }) {
    const { t, locale } = useTranslation();
    const pending = booking.status === 'pending';
    // The date is already the provider's local day: read and written in UTC, so it never moves.
    const date = new Intl.DateTimeFormat(locale, {
        dateStyle: 'full',
        timeZone: 'UTC',
    }).format(new Date(`${booking.local_date}T00:00:00Z`));

    return (
        <Card className={cn(pending && 'border-2 border-primary')}>
            <CardHeader>
                <CardTitle className="flex flex-wrap items-center gap-2">
                    <span className="min-w-0 break-words">{date}</span>
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
                    <p className="font-medium break-words">
                        {booking.customer_name}
                    </p>
                    <p className="break-words">
                        {t('provider.bookings.phone')}: {booking.customer_phone}
                    </p>
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
                                    {priceToCents(option.extra_price) !== 0 &&
                                        ` (${t('providers.show.option_extra_price', { price: formatPrice(locale, option.extra_price) })})`}
                                </li>
                            ))}
                        </ul>
                    )}
                </div>

                <p>
                    {t('provider.bookings.price_note')}:{' '}
                    <span className="font-medium">
                        {formatPrice(locale, centsToPrice(booking.price_cents))}
                    </span>
                </p>

                {/* Only the actions the server allows now: after an answer the flags come back false. */}
                {(booking.can_confirm ||
                    booking.can_reject ||
                    booking.can_cancel) && (
                    <div className="flex flex-wrap gap-2">
                        {booking.can_confirm && (
                            <ConfirmBookingButton bookingId={booking.id} />
                        )}
                        {booking.can_reject && (
                            <ReasonDialog
                                action={rejectBooking.form({
                                    booking: booking.id,
                                })}
                                idPrefix={`reject-${booking.id}`}
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
                                action={cancelBooking.form({
                                    booking: booking.id,
                                })}
                                idPrefix={`cancel-${booking.id}`}
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
                    (which sends no email) and an old cancelled one. Known limit: a booking
                    confirmed before #102 has an outcome but got no email, so for those data the
                    email line can be false. "Sent" means queued: the email leaves later. */}
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
                            </a>
                        </Button>
                    </div>
                )}
                {booking.outcome !== null &&
                    booking.customer_email !== null && (
                        <p className="break-all text-muted-foreground">
                            {t('provider.bookings.email_notice', {
                                email: booking.customer_email,
                            })}
                        </p>
                    )}
                {booking.outcome !== null &&
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
function ConfirmBookingButton({ bookingId }: { bookingId: number }) {
    const { t } = useTranslation();

    return (
        <Form
            {...confirmBooking.form({ booking: bookingId })}
            options={{ preserveScroll: true }}
        >
            {({ processing }) => (
                <Button
                    type="submit"
                    size="sm"
                    className={wrappingLabel}
                    disabled={processing}
                >
                    {processing && <Spinner />}
                    {t('provider.bookings.actions.confirm')}
                </Button>
            )}
        </Form>
    );
}

// Reject or cancel, with an optional message the customer will read on the private page. A
// success closes the window. An error on the message keeps it open, with the error and the text
// written; any other error (a refused change, the request limit) closes it, and is shown at the
// top of the page.
function ReasonDialog({
    action,
    idPrefix,
    trigger,
    title,
    description,
    yes,
}: {
    action: ReturnType<typeof rejectBooking.form>;
    idPrefix: string;
    trigger: string;
    title: string;
    description: string;
    yes: string;
}) {
    const { t } = useTranslation();
    const [open, setOpen] = useState(false);

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button
                    variant="destructive"
                    size="sm"
                    className={wrappingLabel}
                >
                    {trigger}
                </Button>
            </DialogTrigger>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>{title}</DialogTitle>
                    <DialogDescription>{description}</DialogDescription>
                </DialogHeader>
                <Form
                    {...action}
                    options={{ preserveScroll: true }}
                    onSuccess={() => setOpen(false)}
                    onError={(errors) => {
                        if (!errors.reason) {
                            setOpen(false);
                        }
                    }}
                    className="flex flex-col gap-4"
                >
                    {({ processing, errors }) => (
                        <>
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

// Kit texts in English, like the other breadcrumbs, until the kit is translated (issue #54).
ProviderBookingsIndex.layout = {
    breadcrumbs: [
        {
            title: 'Dashboard',
            href: dashboard(),
        },
        {
            title: 'Bookings',
            href: bookingsIndex(),
        },
    ],
};
