import { Form } from '@inertiajs/react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
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
import {
    centsToPrice,
    formatDuration,
    formatOptionExtras,
    formatPrice,
    priceToCents,
} from '@/lib/format';
import { cn } from '@/lib/utils';
import { store } from '@/routes/providers/bookings';
import type { BookingWindow, Service } from '@/types';

type Props = {
    providerSlug: string;
    service: Service;
    bookingWindow: BookingWindow;
};

// The kit has no textarea: same look as Input.
const textareaClass =
    'border-input placeholder:text-muted-foreground flex min-h-20 w-full rounded-md border bg-transparent px-3 py-2 text-base shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 aria-invalid:border-destructive md:text-sm';

// A panel to book one service: options, day, time and the customer's details.
export function BookingSheet({ providerSlug, service, bookingWindow }: Props) {
    const { t, locale } = useTranslation();
    const [open, setOpen] = useState(false);
    const [optionIds, setOptionIds] = useState<number[]>([]);
    const [day, setDay] = useState('');
    // The "start" (UTC) of the chosen slot, sent as starts_at; empty until a time is chosen.
    const [startsAt, setStartsAt] = useState('');

    const { slots, loading, error, reload } = useFreeSlots({
        providerSlug,
        serviceId: service.id,
        optionIds,
        day,
    });

    const chosenOptions = service.options.filter((option) =>
        optionIds.includes(option.id),
    );
    // Indicative total, in whole cents. The minutes that really block the agenda are the server's.
    const totalCents =
        priceToCents(service.price) +
        chosenOptions.reduce(
            (sum, option) => sum + priceToCents(option.extra_price),
            0,
        );
    const extraMinutes = chosenOptions.reduce(
        (sum, option) => sum + option.extra_duration,
        0,
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
                <Button size="sm">{t('booking.form.open')}</Button>
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

                            {service.options.length > 0 && (
                                <fieldset className="grid gap-3">
                                    <legend className="mb-2 text-sm font-medium">
                                        {t('booking.form.options')}
                                    </legend>
                                    {service.options.map((option) => {
                                        const extras = formatOptionExtras(
                                            t,
                                            locale,
                                            option,
                                        );

                                        return (
                                            <div
                                                key={option.id}
                                                className="flex items-center gap-3"
                                            >
                                                <Checkbox
                                                    id={`option-${option.id}`}
                                                    name="options[]"
                                                    value={String(option.id)}
                                                    checked={optionIds.includes(
                                                        option.id,
                                                    )}
                                                    onCheckedChange={(
                                                        checked,
                                                    ) =>
                                                        toggleOption(
                                                            option.id,
                                                            checked === true,
                                                        )
                                                    }
                                                />
                                                <Label
                                                    htmlFor={`option-${option.id}`}
                                                    className="font-normal"
                                                >
                                                    {option.name}
                                                    {extras.length > 0 &&
                                                        ` (${extras.join(', ')})`}
                                                </Label>
                                            </div>
                                        );
                                    })}
                                    <InputError message={errors.options} />
                                </fieldset>
                            )}

                            <dl className="grid grid-cols-[auto_1fr] gap-x-4 gap-y-1 rounded-md bg-muted p-3 text-sm">
                                <dt className="font-medium">
                                    {t('booking.form.summary_price')}
                                </dt>
                                <dd>
                                    {formatPrice(
                                        locale,
                                        centsToPrice(totalCents),
                                    )}
                                </dd>
                                <dt className="font-medium">
                                    {t('booking.form.summary_duration')}
                                </dt>
                                <dd>
                                    {formatDuration(
                                        t,
                                        service.duration_min + extraMinutes,
                                        service.duration_max + extraMinutes,
                                    )}
                                </dd>
                            </dl>

                            <div className="grid gap-2">
                                <Label htmlFor="booking-day">
                                    {t('booking.form.day')}
                                </Label>
                                <Input
                                    id="booking-day"
                                    type="date"
                                    min={bookingWindow.first_day}
                                    max={bookingWindow.last_day}
                                    value={day}
                                    onChange={(event) =>
                                        changeDay(event.target.value)
                                    }
                                />
                            </div>

                            <div className="grid gap-2">
                                <p
                                    id="booking-times"
                                    className="text-sm font-medium"
                                >
                                    {t('booking.form.times')}
                                </p>
                                <InputError message={errors.starts_at} />
                                {day === '' ? (
                                    <p className="text-sm text-muted-foreground">
                                        {t('booking.form.choose_day')}
                                    </p>
                                ) : loading ? (
                                    <p className="flex items-center gap-2 text-sm text-muted-foreground">
                                        <Spinner />
                                        {t('booking.form.slots_loading')}
                                    </p>
                                ) : error ? (
                                    <InputError message={error} />
                                ) : slots.length === 0 ? (
                                    <p className="text-sm text-muted-foreground">
                                        {t('booking.form.no_slots')}
                                    </p>
                                ) : (
                                    <div
                                        role="group"
                                        aria-labelledby="booking-times"
                                        className="flex flex-wrap gap-2"
                                    >
                                        {slots.map((slot) => (
                                            <Button
                                                key={slot.start}
                                                type="button"
                                                size="sm"
                                                variant={
                                                    slot.start === startsAt
                                                        ? 'default'
                                                        : 'outline'
                                                }
                                                aria-pressed={
                                                    slot.start === startsAt
                                                }
                                                onClick={() =>
                                                    setStartsAt(slot.start)
                                                }
                                            >
                                                {slot.time}
                                            </Button>
                                        ))}
                                    </div>
                                )}
                            </div>

                            <div className="grid gap-4">
                                <div className="grid gap-2">
                                    <Label htmlFor="customer_name">
                                        {t('booking.form.name')}
                                    </Label>
                                    <Input
                                        id="customer_name"
                                        name="customer_name"
                                        required
                                        maxLength={150}
                                        autoComplete="name"
                                    />
                                    <InputError
                                        message={errors.customer_name}
                                    />
                                </div>
                                <div className="grid gap-2">
                                    <Label htmlFor="customer_phone">
                                        {t('booking.form.phone')}
                                    </Label>
                                    <Input
                                        id="customer_phone"
                                        name="customer_phone"
                                        type="tel"
                                        required
                                        maxLength={30}
                                        autoComplete="tel"
                                    />
                                    <InputError
                                        message={errors.customer_phone}
                                    />
                                </div>
                                <div className="grid gap-2">
                                    <Label htmlFor="customer_email">
                                        {t('booking.form.email')}
                                    </Label>
                                    <Input
                                        id="customer_email"
                                        name="customer_email"
                                        type="email"
                                        maxLength={255}
                                        autoComplete="email"
                                    />
                                    <InputError
                                        message={errors.customer_email}
                                    />
                                </div>
                                <div className="grid gap-2">
                                    <Label htmlFor="notes">
                                        {t('booking.form.notes')}
                                    </Label>
                                    <textarea
                                        id="notes"
                                        name="notes"
                                        maxLength={1000}
                                        className={cn(textareaClass)}
                                    />
                                    <InputError message={errors.notes} />
                                </div>
                            </div>

                            <InputError message={errors.service} />

                            <Button
                                type="submit"
                                disabled={startsAt === '' || processing}
                            >
                                {processing && <Spinner />}
                                {t('booking.form.submit')}
                            </Button>
                        </>
                    )}
                </Form>
            </SheetContent>
        </Sheet>
    );
}
