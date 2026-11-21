import { Form, Head, Link } from '@inertiajs/react';
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
import { ServiceDurationFields } from '@/components/service-duration-fields';
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
import { useFlashFocus } from '@/hooks/use-flash-focus';
import { useTranslation } from '@/hooks/use-translation';
import { formatDuration, formatPrice } from '@/lib/format';
import { dashboard } from '@/routes';
// The services' own "store", not the profile one of '@/routes/provider'.
import { edit, index, store as storeService } from '@/routes/provider/services';
import type { Category, ProviderService } from '@/types';
import { selectClass } from '@/lib/form-classes';

type Props = {
    services: ProviderService[];
    categories: Category[];
};

// The id of the add form: "add-service-title" is its title.
const ADD_SERVICE = 'add-service';

// The logged-in provider's services, active or not, the most recent first, and the form to add
// one (components/add-panel.tsx): a column that stays in view from xl, a dialog below.
export default function ProviderServicesIndex({ services, categories }: Props) {
    const { t } = useTranslation();
    // After adding or changing a service: its card in view, the focus on its title.
    useFlashFocus();

    return (
        <>
            <Head title={t('provider.services.title')} />
            <div className="flex flex-col gap-6 p-4">
                <header className={PAGE_HEADER_WIDTH}>
                    <h1 className="text-2xl font-semibold">
                        {t('provider.services.title')}
                    </h1>
                    <p className="text-muted-foreground">
                        {t('provider.services.description')}
                    </p>
                </header>

                <AddPanel
                    mode="dialog"
                    id={ADD_SERVICE}
                    title={t('provider.services.add_title')}
                    openLabel={t('provider.services.add_open')}
                >
                    {(onSaved) => (
                        <AddServiceForm
                            categories={categories}
                            onSaved={onSaved}
                        />
                    )}
                </AddPanel>

                <div className={TWO_COLUMNS}>
                    <div className={LIST_COLUMN}>
                        <AddPanelLink
                            id={ADD_SERVICE}
                            label={t('provider.services.add_open')}
                        />
                        {services.length === 0 ? (
                            <p className="text-muted-foreground">
                                {t('provider.services.empty')}
                            </p>
                        ) : (
                            <ul className="flex flex-col gap-4">
                                {services.map((service) => (
                                    <li key={service.id}>
                                        <ServiceCard service={service} />
                                    </li>
                                ))}
                            </ul>
                        )}
                    </div>

                    <AddPanel
                        mode="card"
                        id={ADD_SERVICE}
                        title={t('provider.services.add_title')}
                        openLabel={t('provider.services.add_open')}
                    >
                        {(onSaved) => (
                            <AddServiceForm
                                categories={categories}
                                onSaved={onSaved}
                            />
                        )}
                    </AddPanel>
                </div>
            </div>
        </>
    );
}

function ServiceCard({ service }: { service: ProviderService }) {
    const { t, locale } = useTranslation();

    return (
        // Outlined after a save until the person goes on (useFlashFocus), never by a click.
        <Card className={JUST_SAVED}>
            <CardHeader>
                {/* The target of a save (the "focus" flash service-<id>): reached by script only. */}
                <CardTitle
                    id={`service-${service.id}`}
                    tabIndex={-1}
                    className={`flex flex-wrap items-center gap-2 ${CARD_TITLE_FOCUS}`}
                >
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
            <CardContent>
                <Button asChild variant="outline" size="sm">
                    <Link href={edit(service.id)}>
                        {t('provider.services.edit_button')}
                    </Link>
                </Button>
            </CardContent>
        </Card>
    );
}

// The fields are not controlled: after a validation error what was written stays; after a
// successful submit resetOnSuccess empties them, the select back to its empty first option. Shorter
// than before (#53): category and price side by side when there is room (a container query: the
// form is narrow in the column and in the dialog), the description on 3 lines, closer fields. The
// texts of the durations (#227) are those of ServiceDurationFields.
function AddServiceForm({
    categories,
    onSaved,
}: {
    categories: Category[];
    onSaved: OnSaved;
}) {
    const { t } = useTranslation();
    const submit = useSubmitFocus();

    return (
        <Form
            {...storeService.form()}
            options={{ preserveScroll: true }}
            resetOnSuccess
            onSuccess={(page) => onSaved(page.flash)}
            onFinish={submit.restore}
            className="@container flex flex-col gap-4"
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
                            rows={3}
                            maxLength={1000}
                            autoComplete="off"
                            className="min-h-0"
                        />
                        <InputError message={errors.description} />
                    </div>

                    {/* Side by side when the form is at least 20rem wide, one under the other in a
                        narrow dialog. */}
                    <div className="grid gap-4 @xs:grid-cols-2">
                        <div className="grid content-start gap-2">
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

                        <div className="grid content-start gap-2">
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
                    </div>

                    <ServiceDurationFields errors={errors} />

                    {/* The request limit, for example. */}
                    <InputError message={errors.services} />
                    {/* Disabled while sending: useSubmitFocus gives it the focus back afterwards. */}
                    <Button
                        ref={submit.ref}
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
    );
}

ProviderServicesIndex.layout = {
    breadcrumbs: [
        {
            titleKey: 'nav.dashboard',
            href: dashboard(),
        },
        {
            titleKey: 'provider.services.title',
            href: index(),
        },
    ],
};
