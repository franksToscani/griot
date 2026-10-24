import { Form, Head, usePage } from '@inertiajs/react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
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
import { useTranslation } from '@/hooks/use-translation';
import { formatOptionExtras, priceForInput } from '@/lib/format';
import { dashboard } from '@/routes';
import { edit } from '@/routes/provider/services';
// Explicit names: "store", "update" and "destroy" also exist for the services themselves.
import {
    activate as activateOption,
    deactivate as deactivateOption,
    destroy as destroyOption,
    index as optionsIndex,
    store as storeOption,
    update as updateOption,
} from '@/routes/provider/services/options';
import type { ProviderOption, ProviderService } from '@/types';

type Props = {
    service: ProviderService;
    options: ProviderOption[];
};

// Copied from dashboard.tsx, like edit.tsx: the kit's Button keeps its label on one line at a fixed
// height, and long Italian labels would widen the page at 375 px. cn() uses tailwind-merge.
const wrappingLabel = 'h-auto max-w-full whitespace-normal py-2 text-center';

// The options of one of the provider's services: the list, each with edit and delete, and the
// form to add one.
export default function ProviderServiceOptions({ service, options }: Props) {
    const { t } = useTranslation();
    const { errors } = usePage().props;

    return (
        <>
            <Head title={t('provider.options.title')} />
            <div className="flex flex-col gap-6 p-4">
                <header className="max-w-2xl">
                    <h1 className="text-2xl font-semibold">
                        {t('provider.options.title')}
                    </h1>
                    <p className="text-muted-foreground">{service.name}</p>
                    <p className="text-sm text-muted-foreground">
                        {t('provider.options.description')}
                    </p>
                </header>

                {/* Read from the page, outside the forms: a refused delete, or the request limit. */}
                <div className="max-w-2xl">
                    <InputError message={errors.option} />
                    <InputError message={errors.services} />
                </div>

                {options.length === 0 ? (
                    <p className="text-muted-foreground">
                        {t('provider.options.empty')}
                    </p>
                ) : (
                    <ul className="flex max-w-2xl flex-col gap-4">
                        {/* key = id: each row keeps its own dialogs and values, never another option's. */}
                        {options.map((option) => (
                            <li key={option.id}>
                                <OptionRow
                                    serviceId={service.id}
                                    option={option}
                                />
                            </li>
                        ))}
                    </ul>
                )}

                <AddOptionForm serviceId={service.id} />
            </div>
        </>
    );
}

function OptionRow({
    serviceId,
    option,
}: {
    serviceId: number;
    option: ProviderOption;
}) {
    const { t, locale } = useTranslation();
    // Only the extras that actually add something, as on the public page.
    const extras = formatOptionExtras(t, locale, option);

    return (
        <Card>
            <CardHeader>
                <CardTitle className="flex flex-wrap items-center gap-2">
                    <span className="min-w-0 break-words">{option.name}</span>
                    {!option.is_active && (
                        <Badge variant="outline">
                            {t('provider.options.hidden_badge')}
                        </Badge>
                    )}
                </CardTitle>
                {extras.length > 0 && (
                    <CardDescription>{extras.join(' · ')}</CardDescription>
                )}
            </CardHeader>
            <CardContent className="flex flex-col gap-3">
                <div className="flex flex-wrap gap-2">
                    <EditOptionDialog serviceId={serviceId} option={option} />
                    {option.is_active ? (
                        <HideOptionDialog
                            serviceId={serviceId}
                            optionId={option.id}
                        />
                    ) : (
                        <ShowOptionButton
                            serviceId={serviceId}
                            optionId={option.id}
                        />
                    )}
                    {!option.used && (
                        <DeleteOptionDialog
                            serviceId={serviceId}
                            optionId={option.id}
                        />
                    )}
                </div>
                {option.used && (
                    <p className="text-sm text-muted-foreground">
                        {t('provider.options.delete_blocked')}
                    </p>
                )}
            </CardContent>
        </Card>
    );
}

/**
 * The three fields, shared by the add form and the edit dialog. The ids carry a prefix so that
 * every form on the page has its own (the add form and one dialog per option).
 */
function OptionFields({
    idPrefix,
    option,
    errors,
}: {
    idPrefix: string;
    option?: ProviderOption;
    errors: Partial<Record<'name' | 'extra_price' | 'extra_duration', string>>;
}) {
    const { t } = useTranslation();

    return (
        <>
            <div className="grid gap-2">
                <Label htmlFor={`${idPrefix}-name`}>
                    {t('provider.options.form.name')}
                </Label>
                <Input
                    id={`${idPrefix}-name`}
                    name="name"
                    required
                    maxLength={150}
                    autoComplete="off"
                    defaultValue={option?.name}
                />
                <InputError message={errors.name} />
            </div>

            <div className="grid gap-2">
                <Label htmlFor={`${idPrefix}-price`}>
                    {t('provider.options.form.price')}
                </Label>
                {/* Text, not number: "7,50" with a comma must reach the server as written. */}
                <Input
                    id={`${idPrefix}-price`}
                    name="extra_price"
                    type="text"
                    inputMode="decimal"
                    required
                    maxLength={6}
                    autoComplete="off"
                    aria-describedby={`${idPrefix}-price-help`}
                    defaultValue={
                        option ? priceForInput(option.extra_price) : undefined
                    }
                />
                <p
                    id={`${idPrefix}-price-help`}
                    className="text-sm text-muted-foreground"
                >
                    {t('provider.options.form.price_help')}
                </p>
                <InputError message={errors.extra_price} />
            </div>

            <div className="grid gap-2">
                <Label htmlFor={`${idPrefix}-duration`}>
                    {t('provider.options.form.duration')}
                </Label>
                <Input
                    id={`${idPrefix}-duration`}
                    name="extra_duration"
                    type="number"
                    min={0}
                    max={240}
                    step={5}
                    required
                    aria-describedby={`${idPrefix}-duration-help`}
                    defaultValue={option?.extra_duration}
                />
                <p
                    id={`${idPrefix}-duration-help`}
                    className="text-sm text-muted-foreground"
                >
                    {t('provider.options.form.duration_help')}
                </p>
                <InputError message={errors.extra_duration} />
            </div>
        </>
    );
}

// The edit form in a dialog. A validation error keeps the dialog open, with the error inside and
// the values written; a success closes it, and the next opening reads the saved values again.
function EditOptionDialog({
    serviceId,
    option,
}: {
    serviceId: number;
    option: ProviderOption;
}) {
    const { t } = useTranslation();
    const [open, setOpen] = useState(false);

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button variant="outline" size="sm">
                    {t('provider.options.edit_button')}
                </Button>
            </DialogTrigger>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>
                        {t('provider.options.edit_title')}
                    </DialogTitle>
                    <DialogDescription className="break-words">
                        {option.name}
                    </DialogDescription>
                </DialogHeader>
                <Form
                    {...updateOption.form({
                        service: serviceId,
                        option: option.id,
                    })}
                    options={{ preserveScroll: true }}
                    onSuccess={() => setOpen(false)}
                    className="flex flex-col gap-6"
                >
                    {({ processing, errors }) => (
                        <>
                            <OptionFields
                                idPrefix={`option-${option.id}`}
                                option={option}
                                errors={errors}
                            />
                            <Button
                                type="submit"
                                className={`self-start ${wrappingLabel}`}
                                disabled={processing}
                            >
                                {processing && <Spinner />}
                                {t('provider.options.form.save')}
                            </Button>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}

// Hiding asks for a confirmation: new customers stop seeing the option. The window closes after
// every submit (onFinish), so that an error, shown at the top of the page, is not behind it.
function HideOptionDialog({
    serviceId,
    optionId,
}: {
    serviceId: number;
    optionId: number;
}) {
    const { t } = useTranslation();
    const [open, setOpen] = useState(false);

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button variant="outline" size="sm">
                    {t('provider.options.hide_button')}
                </Button>
            </DialogTrigger>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>
                        {t('provider.options.hide_confirm_title')}
                    </DialogTitle>
                    <DialogDescription>
                        {t('provider.options.hide_confirm_description')}
                    </DialogDescription>
                </DialogHeader>
                <Form
                    {...deactivateOption.form({
                        service: serviceId,
                        option: optionId,
                    })}
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
                                    {t('provider.options.hide_confirm_no')}
                                </Button>
                            </DialogClose>
                            <Button
                                type="submit"
                                variant="destructive"
                                className={wrappingLabel}
                                disabled={processing}
                            >
                                {processing && <Spinner />}
                                {t('provider.options.hide_confirm_yes')}
                            </Button>
                        </DialogFooter>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}

// Showing a hidden option again needs no confirmation.
function ShowOptionButton({
    serviceId,
    optionId,
}: {
    serviceId: number;
    optionId: number;
}) {
    const { t } = useTranslation();

    return (
        <Form
            {...activateOption.form({ service: serviceId, option: optionId })}
            options={{ preserveScroll: true }}
        >
            {({ processing }) => (
                <Button
                    type="submit"
                    variant="outline"
                    size="sm"
                    disabled={processing}
                >
                    {processing && <Spinner />}
                    {t('provider.options.show_button')}
                </Button>
            )}
        </Form>
    );
}

// Deleting asks for a confirmation. A refusal from the server is shown at the top of the page.
function DeleteOptionDialog({
    serviceId,
    optionId,
}: {
    serviceId: number;
    optionId: number;
}) {
    const { t } = useTranslation();
    const [open, setOpen] = useState(false);

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button variant="destructive" size="sm">
                    {t('provider.options.delete_button')}
                </Button>
            </DialogTrigger>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>
                        {t('provider.options.delete_confirm_title')}
                    </DialogTitle>
                    <DialogDescription>
                        {t('provider.options.delete_confirm_description')}
                    </DialogDescription>
                </DialogHeader>
                <Form
                    {...destroyOption.form({
                        service: serviceId,
                        option: optionId,
                    })}
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
                                    {t('provider.options.delete_confirm_no')}
                                </Button>
                            </DialogClose>
                            <Button
                                type="submit"
                                variant="destructive"
                                className={wrappingLabel}
                                disabled={processing}
                            >
                                {processing && <Spinner />}
                                {t('provider.options.delete_confirm_yes')}
                            </Button>
                        </DialogFooter>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}

// Not controlled: after a validation error what was written stays; after a success
// resetOnSuccess empties the fields.
function AddOptionForm({ serviceId }: { serviceId: number }) {
    const { t } = useTranslation();

    return (
        <Card className="max-w-2xl">
            <CardHeader>
                <h2 className="text-xl leading-none font-semibold">
                    {t('provider.options.add_title')}
                </h2>
            </CardHeader>
            <CardContent>
                <Form
                    {...storeOption.form(serviceId)}
                    options={{ preserveScroll: true }}
                    resetOnSuccess
                    className="flex flex-col gap-6"
                >
                    {({ processing, errors }) => (
                        <>
                            <OptionFields
                                idPrefix="new-option"
                                errors={errors}
                            />
                            <Button
                                type="submit"
                                className={`self-start ${wrappingLabel}`}
                                disabled={processing}
                            >
                                {processing && <Spinner />}
                                {t('provider.options.form.submit')}
                            </Button>
                        </>
                    )}
                </Form>
            </CardContent>
        </Card>
    );
}

// Kit texts in English, like the other breadcrumbs, until the kit is translated (issue #54).
// The second point goes back to the edit page of this service.
ProviderServiceOptions.layout = (props: { service: ProviderService }) => ({
    breadcrumbs: [
        {
            title: 'Dashboard',
            href: dashboard(),
        },
        {
            title: 'Services',
            href: edit(props.service.id),
        },
        {
            title: 'Options',
            href: optionsIndex(props.service.id),
        },
    ],
});
