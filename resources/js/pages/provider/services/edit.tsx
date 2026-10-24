import { Form, Head, Link, usePage } from '@inertiajs/react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
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
import { Textarea } from '@/components/ui/textarea';
import { useTranslation } from '@/hooks/use-translation';
import { priceForInput } from '@/lib/format';
import { dashboard } from '@/routes';
import {
    activate,
    deactivate,
    destroy,
    edit,
    index,
    update,
} from '@/routes/provider/services';
import { index as optionsIndex } from '@/routes/provider/services/options';
import type { Category, ProviderService, ServiceBookings } from '@/types';

type Props = {
    service: ProviderService;
    categories: Category[];
    bookings: ServiceBookings;
};

// Same native select as the services list: the kit has no select of its own.
const selectClass =
    'border-input flex h-9 w-full min-w-0 rounded-md border bg-transparent px-3 py-1 text-base shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 aria-invalid:border-destructive md:text-sm';

// Copied from dashboard.tsx: the kit's Button keeps its label on one line at a fixed height, and
// long Italian labels would widen the page at 375 px. cn() uses tailwind-merge, so these win.
const wrappingLabel = 'h-auto max-w-full whitespace-normal py-2 text-center';

// The logged-in provider edits one of its services, switches it on or off, or deletes it.
export default function ProviderServiceEdit({
    service,
    categories,
    bookings,
}: Props) {
    const { t } = useTranslation();

    return (
        <>
            <Head title={t('provider.services.edit_title')} />
            <div className="flex flex-col gap-6 p-4">
                <Card className="max-w-2xl">
                    <CardHeader>
                        <h1 className="text-2xl leading-none font-semibold">
                            {t('provider.services.edit_title')}
                        </h1>
                        <CardDescription>
                            {t('provider.services.edit_description')}
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <EditForm service={service} categories={categories} />
                    </CardContent>
                </Card>

                <Card className="max-w-2xl">
                    <CardHeader>
                        <h2 className="text-xl leading-none font-semibold">
                            {t('provider.options.link_title')}
                        </h2>
                    </CardHeader>
                    <CardContent>
                        <Button
                            asChild
                            variant="outline"
                            className={wrappingLabel}
                        >
                            <Link href={optionsIndex(service.id)}>
                                {t('provider.options.link_button')}
                            </Link>
                        </Button>
                    </CardContent>
                </Card>
                <Card className="max-w-2xl">
                    <CardHeader>
                        <h2 className="text-xl leading-none font-semibold">
                            {t('provider.services.state_title')}
                        </h2>
                    </CardHeader>
                    <CardContent>
                        {service.is_active ? (
                            <DeactivateButton serviceId={service.id} />
                        ) : (
                            <ActivateButton serviceId={service.id} />
                        )}
                    </CardContent>
                </Card>

                <Card className="max-w-2xl">
                    <CardHeader>
                        <h2 className="text-xl leading-none font-semibold">
                            {t('provider.services.delete_title')}
                        </h2>
                    </CardHeader>
                    <CardContent>
                        <DeleteSection
                            serviceId={service.id}
                            hasBookings={bookings.has_bookings}
                        />
                    </CardContent>
                </Card>
            </div>
        </>
    );
}

// The same fields as the creation form, filled in with the saved values. Not controlled: after a
// validation error what was written stays.
function EditForm({
    service,
    categories,
}: {
    service: ProviderService;
    categories: Category[];
}) {
    const { t } = useTranslation();

    return (
        <Form
            {...update.form(service.id)}
            options={{ preserveScroll: true }}
            className="flex flex-col gap-6"
        >
            {({ processing, errors }) => (
                <>
                    <div className="grid gap-2">
                        <Label htmlFor="service-name">
                            {t('provider.services.form.name')}
                        </Label>
                        <Input
                            id="service-name"
                            name="name"
                            required
                            maxLength={150}
                            autoComplete="off"
                            defaultValue={service.name}
                        />
                        <InputError message={errors.name} />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="service-description">
                            {t('provider.services.form.description')}
                        </Label>
                        <Textarea
                            id="service-description"
                            name="description"
                            maxLength={1000}
                            autoComplete="off"
                            defaultValue={service.description ?? undefined}
                        />
                        <InputError message={errors.description} />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="service-category">
                            {t('provider.services.form.category')}
                        </Label>
                        <select
                            id="service-category"
                            name="category_id"
                            required
                            defaultValue={String(service.category.id)}
                            className={selectClass}
                        >
                            <option value="" disabled>
                                {t(
                                    'provider.services.form.category_placeholder',
                                )}
                            </option>
                            {categories.map((category) => (
                                <option key={category.id} value={category.id}>
                                    {category.name}
                                </option>
                            ))}
                        </select>
                        <InputError message={errors.category_id} />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="service-price">
                            {t('provider.services.form.price')}
                        </Label>
                        {/* Text, not number: shown with a comma, "25,50", as the provider writes it. */}
                        <Input
                            id="service-price"
                            name="price"
                            type="text"
                            inputMode="decimal"
                            required
                            maxLength={7}
                            autoComplete="off"
                            aria-describedby="service-price-help"
                            defaultValue={priceForInput(service.price)}
                        />
                        <p
                            id="service-price-help"
                            className="text-sm text-muted-foreground"
                        >
                            {t('provider.services.form.price_help')}
                        </p>
                        <InputError message={errors.price} />
                    </div>

                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="grid gap-2">
                            <Label htmlFor="service-duration-min">
                                {t('provider.services.form.duration_min')}
                            </Label>
                            <Input
                                id="service-duration-min"
                                name="duration_min"
                                type="number"
                                min={5}
                                max={720}
                                step={5}
                                required
                                aria-describedby="service-duration-help"
                                defaultValue={service.duration_min}
                            />
                            <InputError message={errors.duration_min} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="service-duration-max">
                                {t('provider.services.form.duration_max')}
                            </Label>
                            <Input
                                id="service-duration-max"
                                name="duration_max"
                                type="number"
                                min={5}
                                max={720}
                                step={5}
                                required
                                aria-describedby="service-duration-help"
                                defaultValue={service.duration_max}
                            />
                            <InputError message={errors.duration_max} />
                        </div>
                    </div>
                    <p
                        id="service-duration-help"
                        className="-mt-4 text-sm text-muted-foreground"
                    >
                        {t('provider.services.form.duration_help')}
                    </p>

                    <Button
                        type="submit"
                        className={`self-start ${wrappingLabel}`}
                        disabled={processing}
                    >
                        {processing && <Spinner />}
                        {t('provider.services.form.save')}
                    </Button>
                </>
            )}
        </Form>
    );
}

// The request limit is shared by every action of this page: shown wherever the page reloads.
function LimitError() {
    const { errors } = usePage().props;

    return <InputError message={errors.services} />;
}

// Deactivating asks for a confirmation: the customers stop seeing the service.
function DeactivateButton({ serviceId }: { serviceId: number }) {
    const { t } = useTranslation();
    const [open, setOpen] = useState(false);

    return (
        <div className="flex flex-col gap-2">
            <LimitError />
            <Dialog open={open} onOpenChange={setOpen}>
                <DialogTrigger asChild>
                    <Button
                        variant="outline"
                        className={`self-start ${wrappingLabel}`}
                    >
                        {t('provider.services.deactivate')}
                    </Button>
                </DialogTrigger>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>
                            {t('provider.services.deactivate_title')}
                        </DialogTitle>
                        <DialogDescription>
                            {t('provider.services.deactivate_description')}
                        </DialogDescription>
                    </DialogHeader>
                    <Form
                        {...deactivate.form(serviceId)}
                        options={{ preserveScroll: true }}
                        onSuccess={() => setOpen(false)}
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
                                            'provider.services.deactivate_cancel',
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
                                    {t('provider.services.deactivate_confirm')}
                                </Button>
                            </DialogFooter>
                        )}
                    </Form>
                </DialogContent>
            </Dialog>
        </div>
    );
}

// Activating needs no confirmation: it only shows the service again.
function ActivateButton({ serviceId }: { serviceId: number }) {
    const { t } = useTranslation();

    return (
        <Form
            {...activate.form(serviceId)}
            options={{ preserveScroll: true }}
            className="flex flex-col gap-2"
        >
            {({ processing }) => (
                <>
                    <LimitError />
                    <Button
                        type="submit"
                        className={`self-start ${wrappingLabel}`}
                        disabled={processing}
                    >
                        {processing && <Spinner />}
                        {t('provider.services.activate')}
                    </Button>
                </>
            )}
        </Form>
    );
}

// With bookings there is no delete button, only the explanation. The server's refusal ("service")
// is read from the page errors, outside the form: after the redirect the page may show the
// explanation instead of the button, and the message must still be visible.
function DeleteSection({
    serviceId,
    hasBookings,
}: {
    serviceId: number;
    hasBookings: boolean;
}) {
    const { t } = useTranslation();
    const { errors } = usePage().props;
    const [open, setOpen] = useState(false);

    return (
        <div className="flex flex-col gap-3">
            <InputError message={errors.service} />
            {hasBookings ? (
                <p className="text-sm text-muted-foreground">
                    {t('provider.services.delete_blocked')}
                </p>
            ) : (
                <Dialog open={open} onOpenChange={setOpen}>
                    <DialogTrigger asChild>
                        <Button
                            variant="destructive"
                            className={`self-start ${wrappingLabel}`}
                        >
                            {t('provider.services.delete_button')}
                        </Button>
                    </DialogTrigger>
                    <DialogContent>
                        <DialogHeader>
                            <DialogTitle>
                                {t('provider.services.delete_confirm_title')}
                            </DialogTitle>
                            <DialogDescription>
                                {t(
                                    'provider.services.delete_confirm_description',
                                )}
                            </DialogDescription>
                        </DialogHeader>
                        <Form
                            {...destroy.form(serviceId)}
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
                                                'provider.services.delete_confirm_no',
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
                                        {t(
                                            'provider.services.delete_confirm_yes',
                                        )}
                                    </Button>
                                </DialogFooter>
                            )}
                        </Form>
                    </DialogContent>
                </Dialog>
            )}
        </div>
    );
}

// Kit texts in English, like the other breadcrumbs, until the kit is translated (issue #54).
ProviderServiceEdit.layout = (props: { service: ProviderService }) => ({
    breadcrumbs: [
        {
            title: 'Dashboard',
            href: dashboard(),
        },
        {
            title: 'Services',
            href: index(),
        },
        {
            title: 'Edit service',
            href: edit(props.service.id),
        },
    ],
});
