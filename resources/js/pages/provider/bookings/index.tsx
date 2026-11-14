import { Form, Head, Link, usePage } from '@inertiajs/react';
import { useEffect, useRef } from 'react';
import InputError from '@/components/input-error';
import { Pagination } from '@/components/pagination';
import { BookingCard, PAGE_TITLE_ID } from '@/components/provider-booking-card';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { LiveStatus } from '@/components/live-status';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { PROVIDER_INTERVAL, useLiveProps } from '@/hooks/use-live-props';
import { useTranslation } from '@/hooks/use-translation';
import { dashboard } from '@/routes';
import {
    create as createBooking,
    index as bookingsIndex,
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

// Copied from dashboard.tsx, like the other pages: the kit's Button keeps its label on one line at
// a fixed height, and long Italian labels would widen the page at 375 px. cn() uses tailwind-merge.
const wrappingLabel = 'h-auto max-w-full whitespace-normal py-2 text-center';

// Copied from services/index.tsx: a native select with the same look as the kit's Input.
const selectClass =
    'border-input flex h-9 w-full min-w-0 rounded-md border bg-transparent px-3 py-1 text-base shadow-xs outline-none focus-visible:outline-hidden focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 aria-invalid:border-destructive md:text-sm';

const hasActiveFilters = (filters: ProviderBookingFilters): boolean =>
    filters.status !== null || filters.from !== null || filters.to !== null;

// What a polling can change in a card: its presence in the page, its status and its answers.
const bookingsKey = (bookings: ProviderBooking[]): string =>
    bookings
        .map(
            (booking) =>
                `${booking.id}:${booking.status}:${booking.can_confirm}:${booking.can_reject}:${booking.can_cancel}`,
        )
        .join(',');

// The provider's bookings, read only, in the order the server sends: the pending ones first.
export default function ProviderBookingsIndex({ bookings, filters }: Props) {
    const { t } = useTranslation();
    const filtered = hasActiveFilters(filters);
    const { errors } = usePage().props;
    const actionErrors = useRef<HTMLDivElement>(null);

    // The list asks the server again every 20 s (hooks/use-live-props.ts): a booking answered in
    // another tab, or cancelled by the customer, changes here. With a filter on the status it can
    // leave the list. One summary for the whole list in the status region, never one per card.
    const liveMessage = useLiveProps(['bookings'], {
        interval: PROVIDER_INTERVAL,
        enabled: true,
        describe: (next) => {
            const nextBookings = (next.bookings as Paginated<ProviderBooking>)
                .data;

            if (bookingsKey(nextBookings) === bookingsKey(bookings.data)) {
                return null;
            }

            // A card that leaves the list takes the focus with it: if the focus was in one, it goes
            // to the title of the page (tabIndex={-1}), as after an answer that removes the card.
            const focused =
                document.activeElement?.closest('[data-booking-id]');
            const kept = new Set(nextBookings.map((booking) => booking.id));

            if (
                focused instanceof HTMLElement &&
                !kept.has(Number(focused.dataset.bookingId))
            ) {
                requestAnimationFrame(() =>
                    document.getElementById(PAGE_TITLE_ID)?.focus(),
                );
            }

            return t('provider.bookings.live.updated');
        },
    });

    // A refused action comes back to the same scroll position: the message is brought into view.
    // It is read by role="alert"; the focus stays where it was (back on the action's button).
    useEffect(() => {
        if (errors.booking || errors.services) {
            actionErrors.current?.scrollIntoView({ block: 'center' });
        }
    }, [errors.booking, errors.services]);

    return (
        <>
            <Head title={t('provider.bookings.title')} />
            <div className="flex flex-col gap-6 p-4">
                <header className="flex max-w-2xl flex-col gap-1">
                    <h1
                        id={PAGE_TITLE_ID}
                        tabIndex={-1}
                        className="text-2xl font-semibold"
                    >
                        {t('provider.bookings.title')}
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        {filtered
                            ? t('provider.bookings.filters_active')
                            : t('provider.bookings.help')}
                    </p>
                    {/* A booking for a customer who asked by phone or in person. */}
                    <div className="mt-2">
                        <Button size="sm" asChild className={wrappingLabel}>
                            <Link href={createBooking()}>
                                {t('provider.bookings.add')}
                            </Link>
                        </Button>
                    </div>
                </header>

                {/* Read from the page, outside the forms: a refused change, or the request limit. */}
                <div ref={actionErrors} className="max-w-2xl">
                    <InputError message={errors.booking} announce />
                    <InputError message={errors.services} announce />
                </div>

                <LiveStatus message={liveMessage} />

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
                            <li key={booking.id} data-booking-id={booking.id}>
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

ProviderBookingsIndex.layout = {
    breadcrumbs: [
        {
            titleKey: 'nav.dashboard',
            href: dashboard(),
        },
        {
            titleKey: 'provider.bookings.title',
            href: bookingsIndex(),
        },
    ],
};
