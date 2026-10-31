import { Form, Head, Link } from '@inertiajs/react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import { ProviderAvatar } from '@/components/provider-avatar';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
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
import { Separator } from '@/components/ui/separator';
import { Spinner } from '@/components/ui/spinner';
import { useTranslation } from '@/hooks/use-translation';
import { dashboard } from '@/routes';
import { edit, preview, publish, unpublish } from '@/routes/provider';
import { index as availabilityIndex } from '@/routes/provider/availability';
import { index as bookingsIndex } from '@/routes/provider/bookings';
import { index as servicesIndex } from '@/routes/provider/services';
import { show } from '@/routes/providers';
import type { ProviderArea, PublishingStatus } from '@/types';

type Props = {
    provider: ProviderArea;
    publishing: PublishingStatus;
};

// The codes PublishingRequirements can send, each with its text. An unknown code is skipped,
// so a new requirement on the server never shows a raw key on the page.
const MISSING_CODES = ['name', 'city', 'service', 'availability'] as const;
type MissingCode = (typeof MISSING_CODES)[number];

const isMissingCode = (code: string): code is MissingCode =>
    (MISSING_CODES as readonly string[]).includes(code);

// The kit's Button keeps its label on one line at a fixed height: long Italian labels would widen
// the Card at 375 px. cn() uses tailwind-merge, so these classes replace the kit's ones.
const wrappingLabel = 'h-auto max-w-full whitespace-normal py-2 text-center';

// The provider's private area: a greeting and the status of the public profile.
export default function Dashboard({ provider, publishing }: Props) {
    const { t } = useTranslation();

    return (
        <>
            <Head title={t('provider.area.title')} />
            <div className="flex flex-col gap-6 p-4">
                <header className="flex items-center gap-4">
                    <ProviderAvatar
                        name={provider.name}
                        avatarUrl={provider.avatar_url}
                        className="size-16 text-lg"
                    />
                    <div className="min-w-0">
                        <h1 className="text-2xl font-semibold">
                            {t('provider.area.welcome', {
                                name: provider.name,
                            })}
                        </h1>
                        <p className="text-muted-foreground">{provider.city}</p>
                    </div>
                </header>

                <Card className="max-w-2xl">
                    <CardHeader>
                        <CardTitle>{t('provider.area.status')}</CardTitle>
                        <CardDescription>
                            <Badge
                                variant={
                                    provider.is_published
                                        ? 'default'
                                        : 'secondary'
                                }
                                className="whitespace-normal"
                            >
                                {provider.is_published
                                    ? t('provider.area.status_published')
                                    : t('provider.area.status_unpublished')}
                            </Badge>
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="flex flex-wrap gap-2">
                        {/* Always: published or not, the profile can be edited. */}
                        <Button asChild className={wrappingLabel}>
                            <Link href={edit()}>
                                {t('provider.area.edit_profile')}
                            </Link>
                        </Button>
                        <Button
                            asChild
                            variant="outline"
                            className={wrappingLabel}
                        >
                            <Link href={servicesIndex()}>
                                {t('provider.area.services')}
                            </Link>
                        </Button>
                        <Button
                            asChild
                            variant="outline"
                            className={wrappingLabel}
                        >
                            <Link href={availabilityIndex()}>
                                {t('provider.area.availability')}
                            </Link>
                        </Button>
                        <Button
                            asChild
                            variant="outline"
                            className={wrappingLabel}
                        >
                            <Link href={bookingsIndex()}>
                                {t('provider.area.bookings')}
                            </Link>
                        </Button>
                        {/* Always: the owner sees the page as the customers do, published or not. */}
                        <Button
                            asChild
                            variant="outline"
                            className={wrappingLabel}
                        >
                            <Link href={preview()}>
                                {t('provider.area.preview')}
                            </Link>
                        </Button>
                        {/* Only when published: an unpublished page gives a 404 to everyone. */}
                        {provider.is_published && (
                            <Button
                                asChild
                                variant="outline"
                                className={wrappingLabel}
                            >
                                <Link href={show(provider.slug)}>
                                    {t('provider.area.public_page')}
                                </Link>
                            </Button>
                        )}
                    </CardContent>
                    <Separator />
                    <CardContent className="flex flex-col gap-3">
                        <h2 className="font-semibold">
                            {t('provider.publish.title')}
                        </h2>
                        {provider.is_published ? (
                            <>
                                <IncompleteWarning publishing={publishing} />
                                <UnpublishButton />
                            </>
                        ) : (
                            <PublishSection publishing={publishing} />
                        )}
                    </CardContent>
                </Card>
            </div>
        </>
    );
}

// What is still missing and the publish button. "publishing" comes from the server at every
// load: after a refused publish the page is reloaded with the current list.
function PublishSection({ publishing }: { publishing: PublishingStatus }) {
    const { t } = useTranslation();
    const missing = publishing.missing.filter(isMissingCode);

    return (
        <>
            {publishing.can_publish ? (
                <p className="text-sm">{t('provider.publish.ready')}</p>
            ) : missing.length > 0 ? (
                <div className="text-sm">
                    <p>{t('provider.publish.missing_title')}</p>
                    <ul className="list-disc pl-5">
                        {missing.map((code) => (
                            <li key={code}>
                                {t(`provider.publish.missing_${code}`)}
                            </li>
                        ))}
                    </ul>
                </div>
            ) : (
                // Only unknown codes: still not publishable, with the general message.
                <p className="text-sm">{t('provider.publish.blocked')}</p>
            )}
            <Form
                {...publish.form()}
                options={{ preserveScroll: true }}
                className="flex flex-col gap-2"
            >
                {({ processing, errors }) => (
                    <>
                        <InputError message={errors.publish} />
                        <Button
                            type="submit"
                            className={`self-start ${wrappingLabel}`}
                            disabled={!publishing.can_publish || processing}
                        >
                            {processing && <Spinner />}
                            {t('provider.publish.submit')}
                        </Button>
                    </>
                )}
            </Form>
        </>
    );
}

// A published profile that has lost a requirement: the customers still find it, but cannot
// book. A static notice, read when the page loads, so role="status" and not the kit's "alert".
// It only warns: taking the profile off stays the provider's choice, with the button below.
function IncompleteWarning({ publishing }: { publishing: PublishingStatus }) {
    const { t } = useTranslation();

    if (publishing.missing.length === 0) {
        return null;
    }

    const missing = publishing.missing.filter(isMissingCode);

    return (
        <Alert
            variant="destructive"
            role="status"
            className="border-destructive"
        >
            {/* Only unknown codes: no list to show, only what to do. */}
            {missing.length > 0 && (
                // The kit cuts the title to one line: this one is long.
                <AlertTitle className="line-clamp-none">
                    {t('provider.publish.published_incomplete')}
                </AlertTitle>
            )}
            <AlertDescription>
                {missing.length > 0 && (
                    <ul className="list-disc pl-5">
                        {missing.map((code) => (
                            <li key={code}>
                                {t(`provider.publish.missing_${code}`)}
                            </li>
                        ))}
                    </ul>
                )}
                <p>{t('provider.publish.published_incomplete_help')}</p>
            </AlertDescription>
        </Alert>
    );
}

// Taking the profile off asks for a confirmation first: the customers stop seeing it.
function UnpublishButton() {
    const { t } = useTranslation();
    const [open, setOpen] = useState(false);

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button
                    variant="outline"
                    className={`self-start ${wrappingLabel}`}
                >
                    {t('provider.publish.unpublish')}
                </Button>
            </DialogTrigger>
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>
                        {t('provider.publish.unpublish_title')}
                    </DialogTitle>
                    <DialogDescription>
                        {t('provider.publish.unpublish_description')}
                    </DialogDescription>
                </DialogHeader>
                <Form
                    {...unpublish.form()}
                    options={{ preserveScroll: true }}
                    onSuccess={() => setOpen(false)}
                >
                    {({ processing, errors }) => (
                        <div className="flex flex-col gap-4">
                            {/* For example the request limit: the window stays open and shows it. */}
                            <InputError message={errors.publish} />
                            <DialogFooter className="gap-2">
                                <DialogClose asChild>
                                    <Button
                                        type="button"
                                        variant="secondary"
                                        className={wrappingLabel}
                                    >
                                        {t('provider.publish.unpublish_cancel')}
                                    </Button>
                                </DialogClose>
                                <Button
                                    type="submit"
                                    variant="destructive"
                                    className={wrappingLabel}
                                    disabled={processing}
                                >
                                    {processing && <Spinner />}
                                    {t('provider.publish.unpublish_confirm')}
                                </Button>
                            </DialogFooter>
                        </div>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}

Dashboard.layout = {
    breadcrumbs: [
        {
            titleKey: 'nav.dashboard',
            href: dashboard(),
        },
    ],
};
