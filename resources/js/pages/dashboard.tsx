import { Form, Head, Link, usePage } from '@inertiajs/react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import { ProviderAvatar } from '@/components/provider-avatar';
import { ProviderLinkCard } from '@/components/provider-link-card';
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
import {
    focusPageTitle,
    PAGE_TITLE_FOCUS,
    PAGE_TITLE_ID,
    SetupStepsFooter,
    SetupStepsNav,
} from '@/components/setup-steps';
import { useFormFocus } from '@/hooks/use-form-focus';
import { useTranslation } from '@/hooks/use-translation';
import { dashboard } from '@/routes';
import { touchTarget } from '@/lib/touch-target';
import { cn } from '@/lib/utils';
import { edit, preview, publish, unpublish } from '@/routes/provider';
import { index as availabilityIndex } from '@/routes/provider/availability';
import { index as bookingsIndex } from '@/routes/provider/bookings';
import { index as servicesIndex } from '@/routes/provider/services';
import type { ProviderArea, PublishingStatus } from '@/types';
import { wrappingLabel } from '@/lib/form-classes';

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

// Where each missing requirement is done, for the warning of a published profile that lost it
// (IncompleteWarning, docs/bookings.md: the second exception to "no section buttons").
const MISSING_PAGES: Record<MissingCode, () => { url: string }> = {
    name: edit,
    city: edit,
    service: servicesIndex,
    availability: availabilityIndex,
};

// The provider's private area: a greeting, the requests waiting for an answer and the status of the
// public profile. The sections (profile, services, availability, bookings, preview, public page)
// are in the sidebar (components/app-sidebar.tsx).
export default function Dashboard({ provider, publishing }: Props) {
    const { t } = useTranslation();

    return (
        <>
            <Head title={t('provider.area.title')} />
            <div className="flex flex-col gap-6 p-4">
                {/* The guide to the first steps, only while not published. */}
                <SetupStepsNav page="publish" />
                <header className="flex items-center gap-4">
                    <ProviderAvatar
                        name={provider.name}
                        avatarUrl={provider.avatar_url}
                        className="size-16 text-lg"
                    />
                    <div className="min-w-0">
                        <h1
                            id={PAGE_TITLE_ID}
                            tabIndex={-1}
                            className={`text-2xl font-semibold ${PAGE_TITLE_FOCUS}`}
                        >
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
                    <CardContent>
                        <PendingSummary />
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

                <ProviderLinkCard
                    url={provider.public_url}
                    published={provider.is_published}
                />
                <SetupStepsFooter page="publish" />
            </div>
        </>
    );
}

// The requests waiting for an answer (Booking::awaitingAnswer()), from the sidebar's shared prop:
// no query of its own. A link to the list filtered by "in attesa", which also shows the pending
// requests already started (docs/bookings.md): the list can hold more than this number.
function PendingSummary() {
    const { t } = useTranslation();
    const pending = usePage().props.provider_nav?.pending_requests ?? 0;

    if (pending === 0) {
        return <p className="text-sm">{t('provider.area.pending_none')}</p>;
    }

    return (
        <p className="text-sm">
            <Link
                href={bookingsIndex({ query: { status: 'pending' } })}
                className={cn(
                    'font-medium underline underline-offset-4',
                    touchTarget,
                )}
            >
                {pending === 1
                    ? t('provider.area.pending_one')
                    : t('provider.area.pending', { count: pending })}
            </Link>
        </p>
    );
}

// What is still missing and the publish button. "publishing" comes from the server at every
// load: after a refused publish the page is reloaded with the current list.
function PublishSection({ publishing }: { publishing: PublishingStatus }) {
    const { t } = useTranslation();
    const submit = useFormFocus();

    return (
        <>
            {/* What is missing, and where to do it, is said by the guide at the top of the page
                (SetupStepsNav): here one short sentence, never the list again. */}
            <p className="text-sm">
                {publishing.can_publish
                    ? t('provider.publish.ready')
                    : t('provider.publish.complete_steps')}
            </p>
            <Form
                {...publish.form()}
                options={{ preserveScroll: true }}
                onFinish={submit.onFinish}
                className="flex flex-col gap-2"
            >
                {({ processing, errors }) => (
                    <>
                        <InputError message={errors.publish} />
                        <div className="flex flex-wrap gap-2">
                            {/* Step 4 of the guide: look at the page before publishing it. */}
                            <Button
                                asChild
                                variant="outline"
                                className={wrappingLabel}
                            >
                                <Link href={preview()}>
                                    {t('provider.area.preview')}
                                </Link>
                            </Button>
                            {/* Off while something is missing; the server checks again anyway. */}
                            <Button
                                ref={submit.ref}
                                type="submit"
                                className={wrappingLabel}
                                disabled={!publishing.can_publish || processing}
                            >
                                {processing && <Spinner />}
                                {t('provider.publish.submit')}
                            </Button>
                        </div>
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
                                {/* To the page where it is done, its title focused like the
                                    guide's links. */}
                                <Link
                                    href={MISSING_PAGES[code]().url}
                                    onSuccess={focusPageTitle}
                                    className={`underline underline-offset-4 ${touchTarget}`}
                                >
                                    {t(`provider.publish.missing_${code}`)}
                                </Link>
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
    const submit = useFormFocus();

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
                    onFinish={submit.onFinish}
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
                                    ref={submit.ref}
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
