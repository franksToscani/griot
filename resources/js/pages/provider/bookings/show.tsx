import { Head, Link, usePage } from '@inertiajs/react';
import { useEffect, useRef } from 'react';
import InputError from '@/components/input-error';
import { LiveStatus } from '@/components/live-status';
import { BookingCard, PAGE_TITLE_ID } from '@/components/provider-booking-card';
import { PROVIDER_INTERVAL, useLiveProps } from '@/hooks/use-live-props';
import { useTranslation } from '@/hooks/use-translation';
import { touchTarget } from '@/lib/touch-target';
import { cn } from '@/lib/utils';
import { dashboard } from '@/routes';
import { index as bookingsIndex, show } from '@/routes/provider/bookings';
import type { ProviderBooking } from '@/types';

type Props = {
    booking: ProviderBooking;
};

// One booking of the provider, with the same card and the same answers as the list: the page the
// email "nuova prenotazione" links to (docs/bookings.md). Each answer comes back here, with its
// toast; a refused one shows its message at the top of the page, as on the list.
export default function ProviderBookingShow({ booking }: Props) {
    const { t } = useTranslation();
    const { errors } = usePage().props;
    const actionErrors = useRef<HTMLDivElement>(null);

    // The booking is asked again every 20 s (hooks/use-live-props.ts), until no answer is possible
    // any more: then its state cannot change.
    const liveMessage = useLiveProps(['booking'], {
        interval: PROVIDER_INTERVAL,
        enabled:
            booking.can_confirm || booking.can_reject || booking.can_cancel,
        describe: (next) => {
            const status = (next.booking as ProviderBooking).status;

            return status === booking.status
                ? null
                : t('booking.live.status_changed', {
                      status: t(`provider.bookings.status.${status}`),
                  });
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
            <Head title={t('provider.bookings.show.title')} />
            <div className="flex flex-col gap-6 p-4">
                <header className="flex max-w-2xl flex-col gap-2">
                    <Link
                        href={bookingsIndex()}
                        className={cn(
                            'w-fit text-sm underline underline-offset-4',
                            touchTarget,
                        )}
                    >
                        {t('provider.bookings.back')}
                    </Link>
                    <h1
                        id={PAGE_TITLE_ID}
                        tabIndex={-1}
                        className="text-2xl font-semibold"
                    >
                        {t('provider.bookings.show.title')}
                    </h1>
                </header>

                {/* Read from the page, outside the forms: a refused change, or the request limit. */}
                <div ref={actionErrors} className="max-w-2xl">
                    <InputError message={errors.booking} announce />
                    <InputError message={errors.services} announce />
                </div>

                <LiveStatus message={liveMessage} />

                <div className="max-w-2xl">
                    <BookingCard booking={booking} onDetailPage />
                </div>
            </div>
        </>
    );
}

ProviderBookingShow.layout = (props: Props) => ({
    breadcrumbs: [
        {
            titleKey: 'nav.dashboard',
            href: dashboard(),
        },
        {
            titleKey: 'provider.bookings.title',
            href: bookingsIndex(),
        },
        {
            titleKey: 'provider.bookings.show.title',
            href: show(props.booking.id),
        },
    ],
});
