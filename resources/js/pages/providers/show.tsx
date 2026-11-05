import { Head, Link } from '@inertiajs/react';
import type { MouseEvent } from 'react';
import { BookingSheet } from '@/components/booking-sheet';
import { NewTabHint } from '@/components/new-tab-hint';
import { ProviderAvatar } from '@/components/provider-avatar';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Separator } from '@/components/ui/separator';
import { useTranslation } from '@/hooks/use-translation';
import { formatDuration, formatOptionExtras, formatPrice } from '@/lib/format';
import { touchTarget } from '@/lib/touch-target';
import { cn } from '@/lib/utils';
import { dashboard } from '@/routes';
import { index } from '@/routes/providers';
import type { BookingWindow, ProviderProfile, Service } from '@/types';

type Props = {
    provider: ProviderProfile;
    bookingWindow: BookingWindow;
    // True on the owner's preview (provider.preview): a notice, the way back to the dashboard and
    // no booking. False on the real public page.
    preview: boolean;
};

// External links open in a new tab without giving the new page access to this one; alone in
// their line, they get a finger-sized target on phones.
const externalLink = {
    target: '_blank',
    rel: 'noopener noreferrer',
    className: cn('underline underline-offset-4', touchTarget),
} as const;

// The section of the services and its title: "Scegli un servizio" leads there and moves the focus to
// the title (tabIndex={-1}), as the skip link does with the main content.
const SERVICES_ID = 'servizi';
const SERVICES_TITLE_ID = 'servizi-titolo';

function goToServices(event: MouseEvent<HTMLAnchorElement>) {
    const title = document.getElementById(SERVICES_TITLE_ID);

    if (title !== null) {
        event.preventDefault();
        title.focus();
        // Instant: the app has no smooth scrolling (prefers-reduced-motion, docs/accessibility.md).
        title.scrollIntoView();
    }
}

export default function ProvidersShow({
    provider,
    bookingWindow,
    preview,
}: Props) {
    const { t } = useTranslation();
    const { whatsapp, instagram } = provider.contacts;
    // Booking is possible on the real page only, and only with services to choose.
    const canBook = !preview && provider.service_groups.length > 0;
    const categories = provider.service_groups.map((group) => group.category);

    return (
        <>
            <Head title={provider.name}>
                {/* Inertia visits do not get the noindex header: the meta tag covers them. */}
                {preview && <meta name="robots" content="noindex, nofollow" />}
            </Head>
            <div className="flex flex-col gap-6">
                {preview && (
                    <Card>
                        <CardContent>
                            <p className="font-medium">
                                {t('provider.preview.banner')}
                            </p>
                        </CardContent>
                    </Card>
                )}

                <Link
                    href={preview ? dashboard() : index()}
                    className={cn(
                        'text-sm text-muted-foreground underline underline-offset-4',
                        touchTarget,
                    )}
                >
                    {preview
                        ? t('provider.preview.back')
                        : t('providers.show.back')}
                </Link>

                {/* The gallery of the provider's work (#143) will go here, above the header. */}

                {/* On a phone, in a few seconds: who the provider is, what they offer, and how to go
                    on (choose a service, or write on WhatsApp). The same order on wider screens. */}
                <header className="flex flex-col gap-4">
                    <div className="flex items-center gap-4">
                        <ProviderAvatar
                            name={provider.name}
                            avatarUrl={provider.avatar_url}
                            className="size-24 text-2xl"
                        />
                        <div className="flex min-w-0 flex-col gap-2">
                            <h1 className="text-2xl font-semibold">
                                {provider.name}
                            </h1>
                            <p className="text-muted-foreground">
                                {provider.city}
                            </p>
                            {categories.length > 0 && (
                                <ul className="flex flex-wrap gap-2">
                                    {categories.map((category) => (
                                        <li key={category.id}>
                                            <Badge variant="secondary">
                                                {category.name}
                                            </Badge>
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </div>
                    </div>

                    {provider.bio && (
                        // Plain text: line breaks are kept by CSS, nothing is read as HTML.
                        <p className="whitespace-pre-line">{provider.bio}</p>
                    )}

                    {/* The first actions: links, not buttons (an anchor on the page, WhatsApp in a
                        new tab). Full width and 44px high on phones. No sticky bar: it would cover
                        the content with zoom and the focus (WCAG 2.4.11). */}
                    {(canBook || whatsapp?.url || instagram?.url) && (
                        <div className="flex flex-col gap-3 md:flex-row md:flex-wrap md:items-center">
                            {canBook && (
                                <Button asChild className="max-md:w-full">
                                    <a
                                        href={`#${SERVICES_ID}`}
                                        onClick={goToServices}
                                    >
                                        {t('providers.show.choose_service')}
                                    </a>
                                </Button>
                            )}
                            {whatsapp?.url && (
                                <Button
                                    asChild
                                    variant="outline"
                                    className="max-md:w-full"
                                >
                                    <a
                                        href={whatsapp.url}
                                        target="_blank"
                                        rel="noopener noreferrer"
                                    >
                                        {t('providers.show.whatsapp')}
                                        <NewTabHint />
                                    </a>
                                </Button>
                            )}
                            {instagram?.url && (
                                <a href={instagram.url} {...externalLink}>
                                    @{instagram.username}
                                    <NewTabHint />
                                </a>
                            )}
                        </div>
                    )}
                </header>

                <div className="grid gap-6 lg:grid-cols-3">
                    <section
                        id={SERVICES_ID}
                        className="flex flex-col gap-4 lg:col-span-2"
                    >
                        <h2
                            id={SERVICES_TITLE_ID}
                            tabIndex={-1}
                            className="text-xl font-semibold"
                        >
                            {t('providers.show.services')}
                        </h2>
                        {provider.service_groups.length === 0 ? (
                            <p className="text-muted-foreground">
                                {t('providers.show.no_services')}
                            </p>
                        ) : (
                            provider.service_groups.map((group) => (
                                // On phones each service is a card of its own: the card of the
                                // category keeps only its badge there. Unchanged from md up.
                                <Card
                                    key={group.category.id}
                                    className="max-md:gap-3 max-md:border-0 max-md:bg-transparent max-md:py-0 max-md:shadow-none"
                                >
                                    <CardHeader className="max-md:px-0">
                                        <CardTitle>
                                            <Badge variant="secondary">
                                                {group.category.name}
                                            </Badge>
                                        </CardTitle>
                                    </CardHeader>
                                    <CardContent className="max-md:px-0">
                                        <ul className="flex flex-col gap-4">
                                            {group.services.map(
                                                (service, position) => (
                                                    <li
                                                        key={service.id}
                                                        className="flex flex-col gap-4 max-md:rounded-xl max-md:border max-md:bg-card max-md:p-4 max-md:text-card-foreground max-md:shadow-sm"
                                                    >
                                                        {position > 0 && (
                                                            <Separator className="max-md:hidden" />
                                                        )}
                                                        <ServiceItem
                                                            service={service}
                                                            providerSlug={
                                                                provider.slug
                                                            }
                                                            providerName={
                                                                provider.name
                                                            }
                                                            whatsappUrl={
                                                                whatsapp?.url ??
                                                                null
                                                            }
                                                            bookingWindow={
                                                                bookingWindow
                                                            }
                                                            preview={preview}
                                                        />
                                                    </li>
                                                ),
                                            )}
                                        </ul>
                                    </CardContent>
                                </Card>
                            ))
                        )}
                    </section>

                    <aside className="flex flex-col gap-6">
                        <Card>
                            <CardHeader>
                                {/* Headings, reached with the headings list as "Servizi". The h2
                                    inherits the look of the title: Tailwind resets its size,
                                    weight and margins. */}
                                <CardTitle>
                                    <h2>{t('providers.show.hours')}</h2>
                                </CardTitle>
                            </CardHeader>
                            <CardContent>
                                <dl className="grid grid-cols-[auto_1fr] gap-x-4 gap-y-2 text-sm">
                                    {provider.opening_hours.map((day) => (
                                        <div
                                            key={day.weekday}
                                            className="col-span-2 grid grid-cols-subgrid"
                                        >
                                            <dt className="font-medium">
                                                {t(
                                                    `common.weekdays.${day.day}`,
                                                )}
                                            </dt>
                                            <dd>
                                                {day.slots.length === 0 ? (
                                                    <span className="text-muted-foreground">
                                                        {t(
                                                            'providers.show.closed',
                                                        )}
                                                    </span>
                                                ) : (
                                                    day.slots.map((slot) => (
                                                        <span
                                                            key={slot.start}
                                                            className="block"
                                                        >
                                                            {slot.start}–
                                                            {slot.end}
                                                        </span>
                                                    ))
                                                )}
                                            </dd>
                                        </div>
                                    ))}
                                </dl>
                            </CardContent>
                        </Card>

                        {(whatsapp || instagram) && (
                            <Card>
                                <CardHeader>
                                    <CardTitle>
                                        <h2>{t('providers.show.contacts')}</h2>
                                    </CardTitle>
                                </CardHeader>
                                <CardContent>
                                    <dl className="grid grid-cols-[auto_1fr] gap-x-4 gap-y-2 text-sm">
                                        {whatsapp && (
                                            <>
                                                <dt className="font-medium">
                                                    {t(
                                                        'providers.show.whatsapp',
                                                    )}
                                                </dt>
                                                <dd>
                                                    {whatsapp.url ? (
                                                        <a
                                                            href={whatsapp.url}
                                                            {...externalLink}
                                                        >
                                                            {whatsapp.number}
                                                            <NewTabHint />
                                                        </a>
                                                    ) : (
                                                        whatsapp.number
                                                    )}
                                                </dd>
                                            </>
                                        )}
                                        {instagram && (
                                            <>
                                                <dt className="font-medium">
                                                    {t(
                                                        'providers.show.instagram',
                                                    )}
                                                </dt>
                                                <dd>
                                                    {instagram.url ? (
                                                        <a
                                                            href={instagram.url}
                                                            {...externalLink}
                                                        >
                                                            @
                                                            {instagram.username}
                                                            <NewTabHint />
                                                        </a>
                                                    ) : (
                                                        instagram.username
                                                    )}
                                                </dd>
                                            </>
                                        )}
                                    </dl>
                                </CardContent>
                            </Card>
                        )}
                    </aside>
                </div>
            </div>
        </>
    );
}

function ServiceItem({
    service,
    providerSlug,
    providerName,
    whatsappUrl,
    bookingWindow,
    preview,
}: {
    service: Service;
    providerSlug: string;
    providerName: string;
    whatsappUrl: string | null;
    bookingWindow: BookingWindow;
    preview: boolean;
}) {
    const { t, locale } = useTranslation();

    return (
        <div className="flex flex-col gap-2">
            <div className="flex flex-wrap items-baseline justify-between gap-2">
                <h3 className="font-medium">{service.name}</h3>
                <p className="text-sm">
                    {formatPrice(locale, service.price)}
                    {' · '}
                    {formatDuration(
                        t,
                        service.duration_min,
                        service.duration_max,
                    )}
                </p>
            </div>
            {service.description && (
                <p className="text-sm whitespace-pre-line text-muted-foreground">
                    {service.description}
                </p>
            )}
            {service.options.length > 0 && (
                <div className="text-sm">
                    <p className="font-medium">{t('providers.show.options')}</p>
                    <ul className="list-disc pl-5">
                        {service.options.map((option) => {
                            // Only the extras that actually add something.
                            const extras = formatOptionExtras(
                                t,
                                locale,
                                option,
                            );

                            return (
                                <li key={option.id}>
                                    {option.name}
                                    {extras.length > 0 &&
                                        ` (${extras.join(', ')})`}
                                </li>
                            );
                        })}
                    </ul>
                </div>
            )}
            <div>
                {/* In the preview the booking form is not mounted at all: nothing can be sent. */}
                {preview ? (
                    <p className="text-sm text-muted-foreground">
                        {t('provider.preview.no_booking')}
                    </p>
                ) : (
                    <BookingSheet
                        providerSlug={providerSlug}
                        providerName={providerName}
                        service={service}
                        bookingWindow={bookingWindow}
                        whatsappUrl={whatsappUrl}
                    />
                )}
            </div>
        </div>
    );
}
