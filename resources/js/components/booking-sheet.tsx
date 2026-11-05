import { Form } from '@inertiajs/react';
import { useState } from 'react';
import {
    BookingDayAndTimes,
    BookingOptionsFieldset,
    BookingSummary,
    CustomerFields,
} from '@/components/booking-fields';
import InputError from '@/components/input-error';
import { PrivacyLink } from '@/components/privacy-link';
import { Button } from '@/components/ui/button';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
    SheetTrigger,
} from '@/components/ui/sheet';
import { Spinner } from '@/components/ui/spinner';
import { useFreeSlots } from '@/hooks/use-free-slots';
import { useTranslation } from '@/hooks/use-translation';
import { focusFirstInvalid } from '@/lib/form-a11y';
import { slots as slotsRoute } from '@/routes/providers';
import { store } from '@/routes/providers/bookings';
import type { BookingWindow, Service } from '@/types';

type Props = {
    providerSlug: string;
    service: Service;
    bookingWindow: BookingWindow;
};

// A panel to book one service: options, day, time and the customer's details.
export function BookingSheet({ providerSlug, service, bookingWindow }: Props) {
    const { t } = useTranslation();
    const [open, setOpen] = useState(false);
    const [optionIds, setOptionIds] = useState<number[]>([]);
    const [day, setDay] = useState('');
    // The "start" (UTC) of the chosen slot, sent as starts_at; empty until a time is chosen.
    const [startsAt, setStartsAt] = useState('');

    // Wayfinder writes the array as options[]=1&options[]=2.
    const { slots, loading, error, reload } = useFreeSlots(
        day === ''
            ? null
            : slotsRoute.url(providerSlug, {
                  query: { service: service.id, date: day, options: optionIds },
              }),
    );

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

    const reset = () => {
        setOptionIds([]);
        setDay('');
        setStartsAt('');
    };
    const changeOpen = (value: boolean) => {
        setOpen(value);

        if (!value) {
            reset();
        }
    };

    return (
        <Sheet open={open} onOpenChange={changeOpen}>
            <SheetTrigger asChild>
                {/* One "Prenota" per service: screen readers hear which one ("Prenota Box
                    braids"), the screen shows the verb only. The name starts with the visible
                    text, as voice control expects. The normal size, full width on phones. */}
                <Button className="max-md:w-full">
                    {t('booking.form.open')}
                    <span className="sr-only"> {service.name}</span>
                </Button>
            </SheetTrigger>
            <SheetContent className="w-full overflow-y-auto sm:max-w-md">
                <SheetHeader>
                    <SheetTitle>
                        {t('booking.form.title', { service: service.name })}
                    </SheetTitle>
                    <SheetDescription>
                        {t('booking.form.description')}
                    </SheetDescription>
                </SheetHeader>

                <Form
                    id="booking-form"
                    {...store.form(providerSlug)}
                    options={{ preserveScroll: true }}
                    onSuccess={() => {
                        setOpen(false);
                        reset();
                    }}
                    onError={(errors) => {
                        // The time was taken meanwhile: clear the choice and ask again for the
                        // slots of the same day. What the customer typed stays in the fields.
                        if (errors.starts_at) {
                            setStartsAt('');
                            reload();
                        }

                        focusFirstInvalid('booking-form');
                    }}
                    className="flex flex-col gap-6 px-4 pb-6"
                >
                    {({ processing, errors }) => (
                        <>
                            <input
                                type="hidden"
                                name="service"
                                value={service.id}
                            />
                            <input
                                type="hidden"
                                name="starts_at"
                                value={startsAt}
                            />

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

                            <CustomerFields
                                nameLabelKey="booking.form.name"
                                phoneHelpKey="booking.phone.help"
                                errors={errors}
                            />

                            {/* Trap field (honeypot): hidden from people and from screen readers,
                                left empty by a person, filled in by many bots. */}
                            <div className="hidden" aria-hidden="true">
                                <input
                                    type="text"
                                    name="confirm_reference"
                                    tabIndex={-1}
                                    autoComplete="off"
                                    defaultValue=""
                                />
                            </div>

                            {/* No field of their own: read as soon as they appear. */}
                            <InputError message={errors.service} announce />
                            <InputError message={errors.throttle} announce />

                            {/* Never disabled for a missing time: the day field stops the
                                submit with its message (booking-fields.tsx). */}
                            <Button type="submit" disabled={processing}>
                                {processing && <Spinner />}
                                {t('booking.form.submit')}
                            </Button>

                            <p className="text-xs text-muted-foreground">
                                {t('booking.form.privacy_note')} <PrivacyLink />
                            </p>
                        </>
                    )}
                </Form>
            </SheetContent>
        </Sheet>
    );
}
