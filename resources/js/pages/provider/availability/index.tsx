import { Form, Head, usePage } from '@inertiajs/react';
import { useState } from 'react';
import {
    AddPanel,
    AddPanelLink,
    CARD_TITLE_FOCUS,
    JUST_SAVED,
    LIST_COLUMN,
    PAGE_HEADER_WIDTH,
    TWO_COLUMNS,
    useSubmitFocus,
} from '@/components/add-panel';
import type { OnSaved } from '@/components/add-panel';
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
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { useFlashFocus } from '@/hooks/use-flash-focus';
import { useTranslation } from '@/hooks/use-translation';
import { dashboard } from '@/routes';
// Explicit names: "store", "update" and "destroy" also exist for the profile and the services.
import {
    destroy as destroyAvailability,
    index as availabilityIndex,
    store as storeAvailability,
    update as updateAvailability,
} from '@/routes/provider/availability';
import type { AvailabilityWeekday, ProviderAvailability } from '@/types';
import { selectClass, wrappingLabel } from '@/lib/form-classes';
import { touchTarget } from '@/lib/touch-target';

type Props = {
    availabilities: ProviderAvailability[];
    weekdays: AvailabilityWeekday[];
};

type FieldErrors = Partial<
    Record<'weekday' | 'start_time' | 'end_time' | 'services', string>
>;

// The id of the add form: "add-availability-title" is its title.
const ADD_AVAILABILITY = 'add-availability';

// The provider's weekly hours: from Monday, a Card per working day with its slots, one line per
// closed day, and the form to add a slot (components/add-panel.tsx): a column that stays in view
// from xl, a dialog below. The slots come ordered by day and start from the server.
export default function ProviderAvailabilityIndex({
    availabilities,
    weekdays,
}: Props) {
    const { t } = useTranslation();
    const { errors } = usePage().props;
    // After adding a slot: its day in view, the focus on the day's title.
    useFlashFocus();

    return (
        <>
            <Head title={t('provider.availability.title')} />
            <div className="flex flex-col gap-6 p-4">
                <header className={`flex flex-col gap-1 ${PAGE_HEADER_WIDTH}`}>
                    <h1 className="text-2xl font-semibold">
                        {t('provider.availability.title')}
                    </h1>
                    <p className="text-muted-foreground">
                        {t('provider.availability.description')}
                    </p>
                    {/* Folded: three lines less on every visit. A native <details>: Enter or Space
                        on its summary opens it, no script, no animation. */}
                    <details className="text-sm text-muted-foreground">
                        <summary
                            className={`w-fit cursor-pointer rounded-sm font-medium text-foreground outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-hidden ${touchTarget}`}
                        >
                            {t('provider.availability.help_title')}
                        </summary>
                        <ul className="mt-1 list-disc pl-5">
                            <li>{t('provider.availability.help_touch')}</li>
                            <li>{t('provider.availability.help_midnight')}</li>
                            <li>{t('provider.availability.help_timezone')}</li>
                        </ul>
                    </details>
                </header>

                {/* Read from the page, outside the forms: the request limit. */}
                <div className={PAGE_HEADER_WIDTH}>
                    <InputError message={errors.services} />
                </div>

                <AddPanel
                    mode="dialog"
                    id={ADD_AVAILABILITY}
                    title={t('provider.availability.add_title')}
                    openLabel={t('provider.availability.add_open')}
                >
                    {(onSaved) => (
                        <AddAvailabilityForm
                            weekdays={weekdays}
                            onSaved={onSaved}
                        />
                    )}
                </AddPanel>

                <div className={TWO_COLUMNS}>
                    <div className={LIST_COLUMN}>
                        <AddPanelLink
                            id={ADD_AVAILABILITY}
                            label={t('provider.availability.add_open')}
                        />
                        <ul className="flex flex-col gap-4">
                            {weekdays.map((weekday) => (
                                <li key={weekday.weekday}>
                                    <DayCard
                                        weekday={weekday}
                                        weekdays={weekdays}
                                        availabilities={availabilities.filter(
                                            (availability) =>
                                                availability.weekday ===
                                                weekday.weekday,
                                        )}
                                    />
                                </li>
                            ))}
                        </ul>
                    </div>

                    <AddPanel
                        mode="card"
                        id={ADD_AVAILABILITY}
                        title={t('provider.availability.add_title')}
                        openLabel={t('provider.availability.add_open')}
                    >
                        {(onSaved) => (
                            <AddAvailabilityForm
                                weekdays={weekdays}
                                onSaved={onSaved}
                            />
                        )}
                    </AddPanel>
                </div>
            </div>
        </>
    );
}

function DayCard({
    weekday,
    weekdays,
    availabilities,
}: {
    weekday: AvailabilityWeekday;
    weekdays: AvailabilityWeekday[];
    availabilities: ProviderAvailability[];
}) {
    const { t } = useTranslation();
    const day = t(`common.weekdays.${weekday.day}`);
    // The target of a save: the day's title (#availability-day-<1..7>), reached by script only.
    const titleId = `availability-day-${weekday.weekday}`;

    // A closed day: one line, no card.
    if (availabilities.length === 0) {
        return (
            <p
                id={titleId}
                tabIndex={-1}
                className={`px-1 text-muted-foreground ${CARD_TITLE_FOCUS} ${JUST_SAVED}`}
            >
                {t('provider.availability.closed_day', { day })}
            </p>
        );
    }

    return (
        // Outlined after a save until the person goes on (useFlashFocus), never by a click.
        <Card className={JUST_SAVED}>
            <CardHeader>
                <CardTitle
                    id={titleId}
                    tabIndex={-1}
                    className={CARD_TITLE_FOCUS}
                >
                    {day}
                </CardTitle>
            </CardHeader>
            <CardContent>
                <ul className="flex flex-col gap-3">
                    {/* key = id: each row keeps its own dialogs and values, never another slot's. */}
                    {availabilities.map((availability) => (
                        <li key={availability.id}>
                            <AvailabilityRow
                                availability={availability}
                                weekdays={weekdays}
                            />
                        </li>
                    ))}
                </ul>
            </CardContent>
        </Card>
    );
}

function AvailabilityRow({
    availability,
    weekdays,
}: {
    availability: ProviderAvailability;
    weekdays: AvailabilityWeekday[];
}) {
    return (
        <div className="flex flex-wrap items-center justify-between gap-2">
            <span className="font-medium tabular-nums">
                {availability.start_time} – {availability.end_time}
            </span>
            <div className="flex flex-wrap gap-2">
                <EditAvailabilityDialog
                    availability={availability}
                    weekdays={weekdays}
                />
                <DeleteAvailabilityDialog availabilityId={availability.id} />
            </div>
        </div>
    );
}

/**
 * The three fields, shared by the add form and the edit dialog. The ids carry a prefix so that
 * every form on the page has its own (the add form and one dialog per slot). The add form starts
 * with an empty day; the edit dialog with the slot's own day, so it needs no placeholder.
 */
function AvailabilityFields({
    idPrefix,
    availability,
    weekdays,
    errors,
}: {
    idPrefix: string;
    availability?: ProviderAvailability;
    weekdays: AvailabilityWeekday[];
    errors: FieldErrors;
}) {
    const { t } = useTranslation();

    return (
        <>
            <div className="grid gap-2">
                <Label htmlFor={`${idPrefix}-weekday`}>
                    {t('provider.availability.form.weekday')}
                </Label>
                <select
                    id={`${idPrefix}-weekday`}
                    name="weekday"
                    required
                    defaultValue={availability?.weekday ?? ''}
                    className={selectClass}
                >
                    {availability === undefined && (
                        <option value="" disabled>
                            {t(
                                'provider.availability.form.weekday_placeholder',
                            )}
                        </option>
                    )}
                    {weekdays.map((weekday) => (
                        <option key={weekday.weekday} value={weekday.weekday}>
                            {t(`common.weekdays.${weekday.day}`)}
                        </option>
                    ))}
                </select>
                <InputError message={errors.weekday} />
            </div>

            {/* step 300: five minutes, and the browser sends "HH:MM" without seconds. */}
            <div className="grid gap-2">
                <Label htmlFor={`${idPrefix}-start`}>
                    {t('provider.availability.form.start')}
                </Label>
                <Input
                    id={`${idPrefix}-start`}
                    name="start_time"
                    type="time"
                    step={300}
                    required
                    defaultValue={availability?.start_time}
                />
                <InputError message={errors.start_time} />
            </div>

            <div className="grid gap-2">
                <Label htmlFor={`${idPrefix}-end`}>
                    {t('provider.availability.form.end')}
                </Label>
                <Input
                    id={`${idPrefix}-end`}
                    name="end_time"
                    type="time"
                    step={300}
                    required
                    defaultValue={availability?.end_time}
                />
                <InputError message={errors.end_time} />
            </div>
        </>
    );
}

// The edit form in a dialog. A validation error keeps the dialog open, with the error inside and
// the values chosen; a success closes it, and the next opening reads the saved values again. The
// request limit is shown here too: the dialog stays open, so the message at the top would be
// behind it.
function EditAvailabilityDialog({
    availability,
    weekdays,
}: {
    availability: ProviderAvailability;
    weekdays: AvailabilityWeekday[];
}) {
    const { t } = useTranslation();
    const [open, setOpen] = useState(false);

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button variant="outline" size="sm">
                    {t('provider.availability.edit_button')}
                </Button>
            </DialogTrigger>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>
                        {t('provider.availability.edit_title')}
                    </DialogTitle>
                    <DialogDescription className="tabular-nums">
                        {availability.start_time} – {availability.end_time}
                    </DialogDescription>
                </DialogHeader>
                <Form
                    {...updateAvailability.form(availability.id)}
                    options={{ preserveScroll: true }}
                    onSuccess={() => setOpen(false)}
                    className="flex flex-col gap-6"
                >
                    {({ processing, errors }) => (
                        <>
                            <AvailabilityFields
                                idPrefix={`availability-${availability.id}`}
                                availability={availability}
                                weekdays={weekdays}
                                errors={errors}
                            />
                            <InputError message={errors.services} />
                            <Button
                                type="submit"
                                className={`self-start ${wrappingLabel}`}
                                disabled={processing}
                            >
                                {processing && <Spinner />}
                                {t('provider.availability.form.save')}
                            </Button>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}

// Deleting asks for a confirmation. The window closes after every submit (onFinish), so that an
// error, shown at the top of the page, is not behind it.
function DeleteAvailabilityDialog({
    availabilityId,
}: {
    availabilityId: number;
}) {
    const { t } = useTranslation();
    const [open, setOpen] = useState(false);

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button variant="destructive" size="sm">
                    {t('provider.availability.delete_button')}
                </Button>
            </DialogTrigger>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>
                        {t('provider.availability.delete_confirm_title')}
                    </DialogTitle>
                    <DialogDescription>
                        {t('provider.availability.delete_confirm_description')}
                    </DialogDescription>
                </DialogHeader>
                <Form
                    {...destroyAvailability.form(availabilityId)}
                    options={{ preserveScroll: true }}
                    onFinish={() => setOpen(false)}
                >
                    {({ processing }) => (
                        <DialogFooter className="gap-2">
                            <DialogClose asChild>
                                <Button
                                    type="button"
                                    variant="secondary"
                                    className={wrappingLabel}
                                >
                                    {t(
                                        'provider.availability.delete_confirm_no',
                                    )}
                                </Button>
                            </DialogClose>
                            <Button
                                type="submit"
                                variant="destructive"
                                className={wrappingLabel}
                                disabled={processing}
                            >
                                {processing && <Spinner />}
                                {t('provider.availability.delete_confirm_yes')}
                            </Button>
                        </DialogFooter>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}

// Not controlled: after a validation error what was written stays; after a success
// resetOnSuccess empties the fields, the select back to its empty first option. The request
// limit is shown at the top of the page. In a column or a dialog (AddPanel).
function AddAvailabilityForm({
    weekdays,
    onSaved,
}: {
    weekdays: AvailabilityWeekday[];
    onSaved: OnSaved;
}) {
    const { t } = useTranslation();
    const submit = useSubmitFocus();

    return (
        <Form
            {...storeAvailability.form()}
            options={{ preserveScroll: true }}
            resetOnSuccess
            onSuccess={(page) => onSaved(page.flash)}
            onFinish={submit.restore}
            className="flex flex-col gap-4"
        >
            {({ processing, errors }) => (
                <>
                    <AvailabilityFields
                        idPrefix="new-availability"
                        weekdays={weekdays}
                        errors={errors}
                    />
                    {/* Disabled while sending: useSubmitFocus gives it the focus back afterwards. */}
                    <Button
                        ref={submit.ref}
                        type="submit"
                        className={`self-start ${wrappingLabel}`}
                        disabled={processing}
                    >
                        {processing && <Spinner />}
                        {t('provider.availability.form.submit')}
                    </Button>
                </>
            )}
        </Form>
    );
}

ProviderAvailabilityIndex.layout = {
    breadcrumbs: [
        {
            titleKey: 'nav.dashboard',
            href: dashboard(),
        },
        {
            titleKey: 'provider.availability.title',
            href: availabilityIndex(),
        },
    ],
};
