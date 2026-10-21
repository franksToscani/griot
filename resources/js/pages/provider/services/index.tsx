import { Form, Head } from '@inertiajs/react';
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
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import { useTranslation } from '@/hooks/use-translation';
import { formatDuration, formatPrice } from '@/lib/format';
import { dashboard } from '@/routes';
// The services' own "store", not the profile one of '@/routes/provider'.
import { index, store as storeService } from '@/routes/provider/services';
import type { Category, ProviderService } from '@/types';

type Props = {
    services: ProviderService[];
    categories: Category[];
};

// A native select with the same look as the kit's Input: the kit has no select of its own.
const selectClass =
    'border-input flex h-9 w-full min-w-0 rounded-md border bg-transparent px-3 py-1 text-base shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 aria-invalid:border-destructive md:text-sm';

// The logged-in provider's services, active or not, and the form to add one.
export default function ProviderServicesIndex({ services, categories }: Props) {
    const { t } = useTranslation();

    return (
        <>
            <Head title={t('provider.services.title')} />
            <div className="flex flex-col gap-6 p-4">
                <header className="max-w-2xl">
                    <h1 className="text-2xl font-semibold">
                        {t('provider.services.title')}
                    </h1>
                    <p className="text-muted-foreground">
                        {t('provider.services.description')}
                    </p>
                </header>

                {services.length === 0 ? (
                    <p className="text-muted-foreground">
                        {t('provider.services.empty')}
                    </p>
                ) : (
                    <ul className="flex max-w-2xl flex-col gap-4">
                        {services.map((service) => (
                            <li key={service.id}>
                                <ServiceCard service={service} />
                            </li>
                        ))}
                    </ul>
                )}

                <AddServiceForm categories={categories} />
            </div>
        </>
    );
}

function ServiceCard({ service }: { service: ProviderService }) {
    const { t, locale } = useTranslation();

    return (
        <Card>
            <CardHeader>
                <CardTitle className="flex flex-wrap items-center gap-2">
                    <span className="min-w-0 break-words">{service.name}</span>
                    <Badge variant="secondary">{service.category.name}</Badge>
                    <Badge variant={service.is_active ? 'default' : 'outline'}>
                        {service.is_active
                            ? t('provider.services.active')
                            : t('provider.services.inactive')}
                    </Badge>
                </CardTitle>
                <CardDescription>
                    {formatPrice(locale, service.price)}
                    {' · '}
                    {formatDuration(
                        t,
                        service.duration_min,
                        service.duration_max,
                    )}
                </CardDescription>
            </CardHeader>
            {service.description && (
                <CardContent>
                    {/* Plain text: line breaks are kept by CSS, nothing is read as HTML. */}
                    <p className="text-sm whitespace-pre-line text-muted-foreground">
                        {service.description}
                    </p>
                </CardContent>
            )}
        </Card>
    );
}

// The fields are not controlled: after a validation error what was written stays; after a
// successful submit resetOnSuccess empties them, the select back to its empty first option.
function AddServiceForm({ categories }: { categories: Category[] }) {
    const { t } = useTranslation();

    return (
        <Card className="max-w-2xl">
            <CardHeader>
                <h2 className="text-xl leading-none font-semibold">
                    {t('provider.services.add_title')}
                </h2>
            </CardHeader>
            <CardContent>
                <Form
                    {...storeService.form()}
                    options={{ preserveScroll: true }}
                    resetOnSuccess
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
                                    defaultValue=""
                                    className={selectClass}
                                >
                                    <option value="" disabled>
                                        {t(
                                            'provider.services.form.category_placeholder',
                                        )}
                                    </option>
                                    {categories.map((category) => (
                                        <option
                                            key={category.id}
                                            value={category.id}
                                        >
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
                                {/* Text, not number: "25,50" with a comma must reach the server as written. */}
                                <Input
                                    id="service-price"
                                    name="price"
                                    type="text"
                                    inputMode="decimal"
                                    required
                                    maxLength={7}
                                    autoComplete="off"
                                    aria-describedby="service-price-help"
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
                                        {t(
                                            'provider.services.form.duration_min',
                                        )}
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
                                    />
                                    <InputError message={errors.duration_min} />
                                </div>
                                <div className="grid gap-2">
                                    <Label htmlFor="service-duration-max">
                                        {t(
                                            'provider.services.form.duration_max',
                                        )}
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

                            {/* The request limit, for example. */}
                            <InputError message={errors.services} />
                            <Button
                                type="submit"
                                className="h-auto max-w-full self-start py-2 text-center whitespace-normal"
                                disabled={processing}
                            >
                                {processing && <Spinner />}
                                {t('provider.services.form.submit')}
                            </Button>
                        </>
                    )}
                </Form>
            </CardContent>
        </Card>
    );
}

// Kit texts in English, like the other breadcrumbs, until the kit is translated (issue #54).
ProviderServicesIndex.layout = {
    breadcrumbs: [
        {
            title: 'Dashboard',
            href: dashboard(),
        },
        {
            title: 'Services',
            href: index(),
        },
    ],
};
