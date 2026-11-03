import { useEffect, useRef, useState } from 'react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { useTranslation } from '@/hooks/use-translation';
import {
    centsToPrice,
    formatDuration,
    formatOptionExtras,
    formatPrice,
    priceToCents,
} from '@/lib/format';
import { errorId, fieldA11y } from '@/lib/form-a11y';
import { cn } from '@/lib/utils';
import type { BookingWindow, FreeSlot, Service } from '@/types';

// The parts of a booking form shared by the customer's panel (booking-sheet.tsx) and the provider's
// page (provider/bookings/create.tsx). They hold no state: each form keeps its own and passes it in.

// The kit has no textarea: same look as Input.
const textareaClass =
    'border-input placeholder:text-muted-foreground flex min-h-20 w-full rounded-md border bg-transparent px-3 py-2 text-base shadow-xs outline-none focus-visible:outline-hidden focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 aria-invalid:border-destructive md:text-sm';

type OptionsProps = {
    service: Service;
    optionIds: number[];
    onToggle: (id: number, checked: boolean) => void;
    error?: string;
};

// The service's options as checkboxes, sent as options[]; nothing when the service has none.
export function BookingOptionsFieldset({
    service,
    optionIds,
    onToggle,
    error,
}: OptionsProps) {
    const { t, locale } = useTranslation();

    if (service.options.length === 0) {
        return null;
    }

    // One error for the whole group: every checkbox points to it.
    const invalid = error
        ? {
              'aria-invalid': true as const,
              'aria-describedby': errorId('booking-options'),
          }
        : {};

    return (
        <fieldset className="grid gap-3">
            <legend className="mb-2 text-sm font-medium">
                {t('booking.form.options')}
            </legend>
            {service.options.map((option) => {
                const extras = formatOptionExtras(t, locale, option);

                // 44px rows on phones: the box's invisible 44px area (ui/checkbox) does not
                // reach the box of the next row.
                return (
                    <div
                        key={option.id}
                        className="flex items-center gap-3 max-md:min-h-11"
                    >
                        <Checkbox
                            id={`option-${option.id}`}
                            name="options[]"
                            value={String(option.id)}
                            checked={optionIds.includes(option.id)}
                            onCheckedChange={(checked) =>
                                onToggle(option.id, checked === true)
                            }
                            {...invalid}
                        />
                        <Label
                            htmlFor={`option-${option.id}`}
                            className="font-normal"
                        >
                            {option.name}
                            {extras.length > 0 && ` (${extras.join(', ')})`}
                        </Label>
                    </div>
                );
            })}
            <InputError id={errorId('booking-options')} message={error} />
        </fieldset>
    );
}

type SummaryProps = {
    service: Service;
    optionIds: number[];
};

// Indicative price and duration of the service with the chosen options.
export function BookingSummary({ service, optionIds }: SummaryProps) {
    const { t, locale } = useTranslation();

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

    return (
        <dl className="grid grid-cols-[auto_1fr] gap-x-4 gap-y-1 rounded-md bg-muted p-3 text-sm">
            <dt className="font-medium">{t('booking.form.summary_price')}</dt>
            <dd>{formatPrice(locale, centsToPrice(totalCents))}</dd>
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
    );
}

type DayAndTimesProps = {
    bookingWindow: BookingWindow;
    // "YYYY-MM-DD", or empty when no day is chosen yet.
    day: string;
    onDayChange: (day: string) => void;
    // The answer of useFreeSlots for that day.
    slots: FreeSlot[];
    loading: boolean;
    error: string | null;
    // The "start" (UTC) of the chosen slot; empty until a time is chosen.
    startsAt: string;
    onPick: (start: string) => void;
    startsAtError?: string;
};

// The day, inside the booking window, and the free times of that day as buttons.
export function BookingDayAndTimes({
    bookingWindow,
    day,
    onDayChange,
    slots,
    loading,
    error,
    startsAt,
    onPick,
    startsAtError,
}: DayAndTimesProps) {
    const { t } = useTranslation();
    const dayRef = useRef<HTMLInputElement>(null);
    // The browser's own check, before the request: without a time the form is not sent, the
    // browser shows this message on the day (the first problem, in the order of the form) and
    // moves the focus there, as it does for the required name and phone. The server's
    // "starts_at" error stays the safety net.
    const noTime = t('booking.validation.starts_at_required');
    const [missingTime, setMissingTime] = useState(false);

    useEffect(() => {
        dayRef.current?.setCustomValidity(startsAt === '' ? noTime : '');
    }, [startsAt, noTime]);

    // The server's error first; the browser's only while no time is chosen.
    const timeError =
        startsAtError ?? (missingTime && startsAt === '' ? noTime : undefined);

    // Read by screen readers when the times change: nothing is announced before a day is chosen.
    const status =
        day === ''
            ? ''
            : loading
              ? t('booking.form.slots_loading')
              : error
                ? error
                : slots.length === 0
                  ? t('booking.form.no_slots')
                  : t('booking.form.slots_found', { count: slots.length });

    return (
        <>
            <div className="grid gap-2">
                <Label htmlFor="booking-day">{t('booking.form.day')}</Label>
                <Input
                    ref={dayRef}
                    id="booking-day"
                    type="date"
                    min={bookingWindow.first_day}
                    max={bookingWindow.last_day}
                    value={day}
                    onChange={(event) => onDayChange(event.target.value)}
                    onInvalid={() => setMissingTime(true)}
                    {...fieldA11y('booking-day', timeError)}
                />
            </div>

            <div className="grid gap-2">
                <p id="booking-times" className="text-sm font-medium">
                    {t('booking.form.times')}
                </p>
                <p role="status" className="sr-only">
                    {status}
                </p>
                <InputError id={errorId('booking-day')} message={timeError} />
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
                    // No field and no alert: the status line above reads it.
                    <InputError message={error} />
                ) : slots.length === 0 ? (
                    <p className="text-sm text-muted-foreground">
                        {t('booking.form.no_slots')}
                    </p>
                ) : (
                    <div
                        role="group"
                        aria-labelledby="booking-times"
                        aria-describedby={
                            timeError ? errorId('booking-day') : undefined
                        }
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
                                aria-pressed={slot.start === startsAt}
                                onClick={() => onPick(slot.start)}
                            >
                                {slot.time}
                            </Button>
                        ))}
                    </div>
                )}
            </div>
        </>
    );
}

type CustomerFieldsProps = {
    // Translation keys: the customer reads "Nome" and a help that promises the notices, the
    // provider "Nome del cliente" and a help without them.
    nameLabelKey: string;
    phoneHelpKey: string;
    errors: {
        customer_name?: string;
        customer_phone?: string;
        customer_email?: string;
        notes?: string;
    };
};

// Name and phone (required), email and notes (optional) of the customer.
export function CustomerFields({
    nameLabelKey,
    phoneHelpKey,
    errors,
}: CustomerFieldsProps) {
    const { t } = useTranslation();

    return (
        <div className="grid gap-4">
            <div className="grid gap-2">
                <Label htmlFor="customer_name">{t(nameLabelKey)}</Label>
                <Input
                    id="customer_name"
                    name="customer_name"
                    required
                    maxLength={150}
                    autoComplete="name"
                    {...fieldA11y('customer_name', errors.customer_name)}
                />
                <InputError
                    id={errorId('customer_name')}
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
                    {...fieldA11y(
                        'customer_phone',
                        errors.customer_phone,
                        'customer_phone-help',
                    )}
                />
                <p
                    id="customer_phone-help"
                    className="text-sm text-muted-foreground"
                >
                    {t(phoneHelpKey)}
                </p>
                <InputError
                    id={errorId('customer_phone')}
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
                    {...fieldA11y('customer_email', errors.customer_email)}
                />
                <InputError
                    id={errorId('customer_email')}
                    message={errors.customer_email}
                />
            </div>
            <div className="grid gap-2">
                <Label htmlFor="notes">{t('booking.form.notes')}</Label>
                <textarea
                    id="notes"
                    name="notes"
                    maxLength={1000}
                    className={cn(textareaClass)}
                    {...fieldA11y('notes', errors.notes)}
                />
                <InputError id={errorId('notes')} message={errors.notes} />
            </div>
        </div>
    );
}
