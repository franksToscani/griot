import { Head, Link } from '@inertiajs/react';
import { toast } from 'sonner';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { useTranslation } from '@/hooks/use-translation';
import { centsToPrice, formatPrice, priceToCents } from '@/lib/format';
import { show as providerShow } from '@/routes/providers';
import type { BookingConfirmation } from '@/types';

type Props = {
    booking: BookingConfirmation;
    // The whole private address: the only prop that holds the secret code.
    private_url: string;
};

// The private page of a booking, reached with the secret link.
export default function BookingsShow({ booking, private_url }: Props) {
    const { t, locale } = useTranslation();

    // Same sum as the booking form, in whole cents. Indicative: no paid price is stored.
    const totalCents =
        priceToCents(booking.service.price) +
        booking.options.reduce(
            (sum, option) => sum + priceToCents(option.extra_price),
            0,
        );

    // The date is already the provider's local day: read at midnight UTC and formatted in UTC,
    // so the browser's time zone cannot move it to another day.
    const date = new Intl.DateTimeFormat(locale, {
        dateStyle: 'full',
        timeZone: 'UTC',
    }).format(new Date(`${booking.local_date}T00:00:00Z`));

    const copyLink = async () => {
        try {
            await navigator.clipboard.writeText(private_url);
            toast.success(t('booking.confirmation.copied'));
        } catch {
            // Not allowed or not available: the field stays there to select and copy by hand.
        }
    };

    return (
        <>
            <Head title={t('booking.confirmation.title')}>
                {/* The headers of the response cover a full page load; Inertia visits need the meta tags. */}
                <meta name="robots" content="noindex, nofollow" />
                <meta name="referrer" content="no-referrer" />
            </Head>
            <div className="mx-auto flex w-full max-w-2xl flex-col gap-6">
                <h1 className="text-2xl font-semibold">
                    {t('booking.confirmation.title')}
                </h1>

                <Card>
                    <CardContent>
                        <dl className="grid gap-4 text-sm sm:grid-cols-[auto_1fr] sm:gap-x-6">
                            <dt className="font-medium">
                                {t('booking.confirmation.status')}
                            </dt>
                            <dd>
                                <p className="text-base font-semibold">
                                    {t(
                                        `booking.confirmation.status_${booking.status}`,
                                    )}
                                </p>
                                {booking.status === 'pending' && (
                                    <p className="text-muted-foreground">
                                        {t('booking.confirmation.pending_note')}
                                    </p>
                                )}
                            </dd>

                            <dt className="font-medium">
                                {t('booking.confirmation.provider')}
                            </dt>
                            <dd>
                                <Link
                                    href={providerShow(booking.provider.slug)}
                                    className="underline underline-offset-4"
                                >
                                    {booking.provider.name}
                                </Link>
                                <span className="text-muted-foreground">
                                    {' · '}
                                    {booking.provider.city}
                                </span>
                            </dd>

                            <dt className="font-medium">
                                {t('booking.confirmation.service')}
                            </dt>
                            <dd>{booking.service.name}</dd>

                            {booking.options.length > 0 && (
                                <>
                                    <dt className="font-medium">
                                        {t('booking.confirmation.options')}
                                    </dt>
                                    <dd>
                                        <ul className="list-disc pl-5">
                                            {booking.options.map(
                                                (option, position) => (
                                                    <li key={position}>
                                                        {option.name}
                                                    </li>
                                                ),
                                            )}
                                        </ul>
                                    </dd>
                                </>
                            )}

                            <dt className="font-medium">
                                {t('booking.confirmation.when')}
                            </dt>
                            <dd>
                                <span className="block first-letter:uppercase">
                                    {date}
                                </span>
                                <span className="block">
                                    {booking.local_start}–{booking.local_end}
                                </span>
                            </dd>

                            <dt className="font-medium">
                                {t('booking.confirmation.price')}
                            </dt>
                            <dd>
                                {formatPrice(locale, centsToPrice(totalCents))}
                            </dd>
                        </dl>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>
                            {t('booking.confirmation.link_title')}
                        </CardTitle>
                    </CardHeader>
                    <CardContent className="flex flex-col gap-3">
                        <div className="flex flex-col gap-2 sm:flex-row">
                            <Input
                                readOnly
                                value={private_url}
                                aria-label={t(
                                    'booking.confirmation.link_title',
                                )}
                                onFocus={(event) => event.target.select()}
                            />
                            <Button type="button" onClick={copyLink}>
                                {t('booking.confirmation.copy')}
                            </Button>
                        </div>
                        <p className="text-sm text-muted-foreground">
                            {t('booking.confirmation.link_help')}
                        </p>
                    </CardContent>
                </Card>
            </div>
        </>
    );
}
