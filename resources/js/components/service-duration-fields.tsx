import { useEffect, useRef, useState } from 'react';
import InputError from '@/components/input-error';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useTranslation } from '@/hooks/use-translation';
import { clockTime, formatMinutes } from '@/lib/format';

// The example of the live preview: a booking at 09:00. With the longest duration allowed, 12 hours,
// it still ends the same day, at 21:00.
const EXAMPLE_START = 9 * 60;

// The limits of ServiceRules: from 5 to 720 minutes, in steps of 5.
const DURATION = { min: 5, max: 720, step: 5 };

type Props = {
    // The saved values on the edit page; nothing on the creation form.
    defaultMax?: number;
    defaultMin?: number;
    errors: { duration_min?: string; duration_max?: string };
};

// The minutes written in the field, or null while they are not a duration the server accepts.
function validMinutes(value: string): number | null {
    const minutes = Number(value);

    return value.trim() !== '' &&
        Number.isInteger(minutes) &&
        minutes >= DURATION.min &&
        minutes <= DURATION.max &&
        minutes % DURATION.step === 0
        ? minutes
        : null;
}

// The two durations of a service (#53, docs/bookings.md). First the time kept free in the agenda,
// required, the only one that counts for the free times, with an example that follows what is
// written; then the shortest duration, optional, which only the customer reads. The fields are not
// controlled, like the rest of the form: after a validation error what was written stays.
export function ServiceDurationFields({
    defaultMax,
    defaultMin,
    errors,
}: Props) {
    const { t } = useTranslation();
    const maxRef = useRef<HTMLInputElement>(null);
    const [max, setMax] = useState(
        defaultMax === undefined ? '' : String(defaultMax),
    );

    // resetOnSuccess empties the fields of the creation form and then sends "reset" on the form:
    // the example follows the emptied field.
    useEffect(() => {
        const form = maxRef.current?.form;

        if (!form) {
            return;
        }

        const follow = () =>
            queueMicrotask(() => setMax(maxRef.current?.value ?? ''));
        form.addEventListener('reset', follow);

        return () => form.removeEventListener('reset', follow);
    }, []);

    const minutes = validMinutes(max);

    return (
        <>
            <div className="grid gap-2">
                <Label htmlFor="service-duration-max">
                    {t('provider.services.form.duration_max')}
                </Label>
                <Input
                    ref={maxRef}
                    id="service-duration-max"
                    name="duration_max"
                    type="number"
                    min={DURATION.min}
                    max={DURATION.max}
                    step={DURATION.step}
                    required
                    aria-describedby="service-duration-max-help service-duration-preview"
                    defaultValue={defaultMax}
                    onInput={(event) => setMax(event.currentTarget.value)}
                />
                <p
                    id="service-duration-max-help"
                    className="text-sm text-muted-foreground"
                >
                    {t('provider.services.form.duration_max_help')}
                </p>
                {/* Plain text, read with the field (aria-describedby): not announced at every key
                    pressed. Empty while the minutes are not a valid duration. */}
                <p id="service-duration-preview" className="text-sm">
                    {minutes !== null &&
                        t('provider.services.form.duration_preview', {
                            start: clockTime(EXAMPLE_START),
                            end: clockTime(EXAMPLE_START + minutes),
                            duration: formatMinutes(t, minutes),
                        })}
                </p>
                <InputError message={errors.duration_max} />
            </div>

            <div className="grid gap-2">
                <Label htmlFor="service-duration-min">
                    {t('provider.services.form.duration_min')}
                </Label>
                <Input
                    id="service-duration-min"
                    name="duration_min"
                    type="number"
                    min={DURATION.min}
                    max={DURATION.max}
                    step={DURATION.step}
                    aria-describedby="service-duration-min-help"
                    defaultValue={defaultMin}
                />
                <p
                    id="service-duration-min-help"
                    className="text-sm text-muted-foreground"
                >
                    {t('provider.services.form.duration_min_help')}
                </p>
                <InputError message={errors.duration_min} />
            </div>
        </>
    );
}
