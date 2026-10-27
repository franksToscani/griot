import { Form, Head, Link } from '@inertiajs/react';
import { toast } from 'sonner';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
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
import { Spinner } from '@/components/ui/spinner';
import { useTranslation } from '@/hooks/use-translation';
import { centsToPrice, formatPrice, priceToCents } from '@/lib/format';
import { show as providerShow } from '@/routes/providers';
import type { BookingConfirmation } from '@/types';

type Props = {
    booking: BookingConfirmation;
    // The whole private address: the only prop that holds the secret code.
    private_url: string;
    // Whether the customer can still cancel online; the server checks again on submit.
    can_cancel: boolean;
};

// The private page of a booking, reached with the secret link.
export default function BookingsShow({
    booking,
    private_url,
    can_cancel,
}: Props) {
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

                            {/* The provider's message, as plain text: never read as HTML or links. */}
                            {booking.status === 'cancelled' &&
                                booking.cancellation_reason !== null && (
                                    <>
                                        <dt className="font-medium">
                                            {t(
                                                'booking.confirmation.cancellation_reason',
                                            )}
                                        </dt>
                                        <dd className="break-words whitespace-pre-line">
                                            {booking.cancellation_reason}
                                        </dd>
                                    </>
                                )}

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

                {booking.status !== 'cancelled' &&
                    (can_cancel ? (
                        <CancelBooking privateUrl={private_url} />
                    ) : (
                        <p className="text-sm text-muted-foreground">
                            {t('booking.cancel.too_late')}
                        </p>
                    ))}

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

// The cancel button and its confirmation window. After a submit the server redirects back to
// this page: cancelled (the button disappears) or too late (can_cancel becomes false).
function CancelBooking({ privateUrl }: { privateUrl: string }) {
    const { t } = useTranslation();

    return (
        <div>
            <Dialog>
                <DialogTrigger asChild>
                    <Button variant="destructive">
                        {t('booking.cancel.button')}
                    </Button>
                </DialogTrigger>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>
                            {t('booking.cancel.confirm_title')}
                        </DialogTitle>
                        <DialogDescription>
                            {t('booking.cancel.confirm_description')}
                        </DialogDescription>
                    </DialogHeader>
                    {/* The address of this page plus "/cancel": the code stays in one prop only. */}
                    <Form
                        action={`${privateUrl}/cancel`}
                        method="post"
                        options={{ preserveScroll: true }}
                    >
                        {({ processing, errors }) => (
                            <div className="flex flex-col gap-4">
                                <InputError message={errors.cancel} />
                                <DialogFooter className="gap-2">
                                    <DialogClose asChild>
                                        <Button
                                            type="button"
                                            variant="secondary"
                                        >
                                            {t('booking.cancel.confirm_no')}
                                        </Button>
                                    </DialogClose>
                                    <Button
                                        type="submit"
                                        variant="destructive"
                                        disabled={processing}
                                    >
                                        {processing && <Spinner />}
                                        {t('booking.cancel.confirm_yes')}
                                    </Button>
                                </DialogFooter>
                            </div>
                        )}
                    </Form>
                </DialogContent>
            </Dialog>
        </div>
    );
}
