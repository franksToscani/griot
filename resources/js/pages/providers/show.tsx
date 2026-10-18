import { Head, Link } from '@inertiajs/react';
import { BookingSheet } from '@/components/booking-sheet';
import { ProviderAvatar } from '@/components/provider-avatar';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Separator } from '@/components/ui/separator';
import { useTranslation } from '@/hooks/use-translation';
import { formatDuration, formatOptionExtras, formatPrice } from '@/lib/format';
import { index } from '@/routes/providers';
import type { BookingWindow, ProviderProfile, Service } from '@/types';

type Props = {
    provider: ProviderProfile;
    bookingWindow: BookingWindow;
};

// External links open in a new tab without giving the new page access to this one.
const externalLink = {
    target: '_blank',
    rel: 'noopener noreferrer',
    className: 'underline underline-offset-4',
} as const;

export default function ProvidersShow({ provider, bookingWindow }: Props) {
    const { t } = useTranslation();
    const { whatsapp, instagram } = provider.contacts;

    return (
        <>
            <Head title={provider.name} />
            <div className="flex flex-col gap-6">
                <Link
                    href={index()}
                    className="text-sm text-muted-foreground underline underline-offset-4"
                >
                    {t('providers.show.back')}
                </Link>

                <header className="flex items-center gap-4">
                    <ProviderAvatar
                        name={provider.name}
                        avatarUrl={provider.avatar_url}
                        className="size-20 text-xl"
                    />
                    <div className="min-w-0">
                        <h1 className="text-2xl font-semibold">
                            {provider.name}
                        </h1>
                        <p className="text-muted-foreground">{provider.city}</p>
                    </div>
                </header>

                {provider.bio && (
                    // Plain text: line breaks are kept by CSS, nothing is read as HTML.
                    <p className="whitespace-pre-line">{provider.bio}</p>
                )}

                <div className="grid gap-6 lg:grid-cols-3">
                    <section className="flex flex-col gap-4 lg:col-span-2">
                        <h2 className="text-xl font-semibold">
                            {t('providers.show.services')}
                        </h2>
                        {provider.service_groups.length === 0 ? (
                            <p className="text-muted-foreground">
                                {t('providers.show.no_services')}
                            </p>
                        ) : (
                            provider.service_groups.map((group) => (
                                <Card key={group.category.id}>
                                    <CardHeader>
                                        <CardTitle>
                                            <Badge variant="secondary">
                                                {group.category.name}
                                            </Badge>
                                        </CardTitle>
                                    </CardHeader>
                                    <CardContent>
                                        <ul className="flex flex-col gap-4">
                                            {group.services.map(
                                                (service, position) => (
                                                    <li
                                                        key={service.id}
                                                        className="flex flex-col gap-4"
                                                    >
                                                        {position > 0 && (
                                                            <Separator />
                                                        )}
                                                        <ServiceItem
                                                            service={service}
                                                            providerSlug={
                                                                provider.slug
                                                            }
                                                            bookingWindow={
                                                                bookingWindow
                                                            }
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
                                <CardTitle>
                                    {t('providers.show.hours')}
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
                                        {t('providers.show.contacts')}
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
    bookingWindow,
}: {
    service: Service;
    providerSlug: string;
    bookingWindow: BookingWindow;
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
                <BookingSheet
                    providerSlug={providerSlug}
                    service={service}
                    bookingWindow={bookingWindow}
                />
            </div>
        </div>
    );
}
