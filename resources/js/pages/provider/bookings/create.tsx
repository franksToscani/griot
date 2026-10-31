import { Form, Head, Link } from '@inertiajs/react';
import { useState } from 'react';
import {
    BookingDayAndTimes,
    BookingOptionsFieldset,
    BookingSummary,
    CustomerFields,
} from '@/components/booking-fields';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { useFreeSlots } from '@/hooks/use-free-slots';
import { useTranslation } from '@/hooks/use-translation';
import { dashboard } from '@/routes';
// Explicit names: '@/routes/providers' has the public "slots" and "bookings.store".
import {
    create as createBooking,
    index as bookingsIndex,
    slots as providerSlots,
    store as storeBooking,
} from '@/routes/provider/bookings';
import { index as servicesIndex } from '@/routes/provider/services';
import type { BookingWindow, Service } from '@/types';

type Props = {
    services: Service[];
    bookingWindow: BookingWindow;
    published: boolean;
};

// Copied from dashboard.tsx, like the other pages: the kit's Button keeps its label on one line at
// a fixed height, and long Italian labels would widen the page at 375 px. cn() uses tailwind-merge.
const wrappingLabel = 'h-auto max-w-full whitespace-normal py-2 text-center';

// Copied from services/index.tsx: a native select with the same look as the kit's Input.
const selectClass =
    'border-input flex h-9 w-full min-w-0 rounded-md border bg-transparent px-3 py-1 text-base shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 aria-invalid:border-destructive md:text-sm';

// The provider writes a booking for a customer who asked by phone or in person: born confirmed,
// with no email to anyone.
export default function ProviderBookingCreate({
    services,
    bookingWindow,
    published,
}: Props) {
    const { t } = useTranslation();

    return (
        <>
            <Head title={t('provider.bookings.create.title')} />
            <div className="flex flex-col gap-6 p-4">
                <header className="flex max-w-xl flex-col gap-1">
                    <h1 className="text-2xl font-semibold">
                        {t('provider.bookings.create.title')}
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        {t('provider.bookings.create.help')}
                    </p>
                </header>

                {/* Unpublished: no time is bookable, so the form could only fail. */}
                {!published ? (
                    <Explanation
                        text={t('provider.bookings.create.unpublished')}
                        link={t('provider.bookings.create.unpublished_link')}
                        href={dashboard()}
                    />
                ) : services.length === 0 ? (
                    <Explanation
                        text={t('provider.bookings.create.no_services')}
                        link={t('provider.bookings.create.no_services_link')}
                        href={servicesIndex()}
                    />
                ) : (
                    <BookingForm
                        services={services}
                        bookingWindow={bookingWindow}
                    />
                )}

                <div>
                    <Button
                        variant="ghost"
                        size="sm"
                        asChild
                        className={wrappingLabel}
                    >
                        <Link href={bookingsIndex()}>
                            {t('provider.bookings.create.back')}
                        </Link>
                    </Button>
                </div>
            </div>
        </>
    );
}

function Explanation({
    text,
    link,
    href,
}: {
    text: string;
    link: string;
    href: ReturnType<typeof dashboard>;
}) {
    return (
        <div className="flex max-w-xl flex-col items-start gap-3">
            <p className="text-muted-foreground">{text}</p>
            <Button variant="outline" asChild className={wrappingLabel}>
                <Link href={href}>{link}</Link>
            </Button>
        </div>
    );
}

function BookingForm({
    services,
    bookingWindow,
}: {
    services: Service[];
    bookingWindow: BookingWindow;
}) {
    const { t } = useTranslation();
    // The chosen service's id as the select gives it; empty until one is chosen.
    const [serviceId, setServiceId] = useState('');
    const [optionIds, setOptionIds] = useState<number[]>([]);
    const [day, setDay] = useState('');
    // The "start" (UTC) of the chosen slot, sent as starts_at; empty until a time is chosen.
    const [startsAt, setStartsAt] = useState('');

    const service =
        services.find((other) => String(other.id) === serviceId) ?? null;

    // The provider's own slots: no minimum notice. Wayfinder writes the array as options[]=1&options[]=2.
    const { slots, loading, error, reload } = useFreeSlots(
        service === null || day === ''
            ? null
            : providerSlots.url({
                  query: { service: service.id, date: day, options: optionIds },
              }),
    );

    const changeService = (value: string) => {
        setServiceId(value);
        // The options belong to the service, and the duration changes the free times.
        setOptionIds([]);
        setStartsAt('');
    };

    const toggleOption = (id: number, checked: boolean) => {
        setOptionIds((ids) =>
            checked ? [...ids, id] : ids.filter((other) => other !== id),
        );
        // Other options change the duration, so the free times change too.
        setStartsAt('');
    };

    const changeDay = (value: string) => {
        setDay(value);
        setStartsAt('');
    };

    return (
        <Form
            {...storeBooking.form()}
            options={{ preserveScroll: true }}
            onError={(errors) => {
                // The time is no longer free: clear the choice and ask again for the slots of the
                // same day. What the provider typed stays in the fields.
                if (errors.starts_at) {
                    setStartsAt('');
                    reload();
                }
            }}
            className="flex max-w-xl flex-col gap-6"
        >
            {({ processing, errors }) => (
                <>
                    <input type="hidden" name="starts_at" value={startsAt} />

                    <div className="grid gap-2">
                        <Label htmlFor="booking-service">
                            {t('provider.bookings.create.service')}
                        </Label>
                        <select
                            id="booking-service"
                            name="service"
                            required
                            value={serviceId}
                            onChange={(event) =>
                                changeService(event.target.value)
                            }
                            className={selectClass}
                        >
                            <option value="" disabled>
                                {t('provider.bookings.create.choose_service')}
                            </option>
                            {services.map((other) => (
                                <option key={other.id} value={other.id}>
                                    {other.name}
                                </option>
                            ))}
                        </select>
                        <InputError message={errors.service} />
                    </div>

                    {service !== null && (
                        <>
                            <BookingOptionsFieldset
                                service={service}
                                optionIds={optionIds}
                                onToggle={toggleOption}
                                error={errors.options}
                            />

                            <BookingSummary
                                service={service}
                                optionIds={optionIds}
                            />

                            <BookingDayAndTimes
                                bookingWindow={bookingWindow}
                                day={day}
                                onDayChange={changeDay}
                                slots={slots}
                                loading={loading}
                                error={error}
                                startsAt={startsAt}
                                onPick={setStartsAt}
                                startsAtError={errors.starts_at}
                            />
                        </>
                    )}

                    <fieldset className="grid gap-4">
                        <legend className="mb-2 text-sm font-medium">
                            {t('provider.bookings.create.customer')}
                        </legend>
                        <CustomerFields
                            nameLabelKey="provider.bookings.create.name"
                            phoneHelpKey="provider.bookings.create.phone_help"
                            errors={errors}
                        />
                    </fieldset>

                    <Button
                        type="submit"
                        disabled={startsAt === '' || processing}
                        className={wrappingLabel}
                    >
                        {processing && <Spinner />}
                        {t('provider.bookings.create.submit')}
                    </Button>
                </>
            )}
        </Form>
    );
}

ProviderBookingCreate.layout = {
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
            titleKey: 'provider.bookings.create.title',
            href: createBooking(),
        },
    ],
};
