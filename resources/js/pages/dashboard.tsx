import { Head, Link } from '@inertiajs/react';
import { ProviderAvatar } from '@/components/provider-avatar';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { useTranslation } from '@/hooks/use-translation';
import { dashboard } from '@/routes';
import { edit } from '@/routes/provider';
import { show } from '@/routes/providers';
import type { ProviderArea } from '@/types';

type Props = {
    provider: ProviderArea;
};

// The provider's private area: a greeting and the status of the public profile.
export default function Dashboard({ provider }: Props) {
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
                        <Button asChild>
                            <Link href={edit()}>
                                {t('provider.area.edit_profile')}
                            </Link>
                        </Button>
                        {/* Only when published: an unpublished page gives a 404 (the owner's preview is issue #42). */}
                        {provider.is_published && (
                            <Button asChild variant="outline">
                                <Link href={show(provider.slug)}>
                                    {t('provider.area.public_page')}
                                </Link>
                            </Button>
                        )}
                    </CardContent>
                </Card>
            </div>
        </>
    );
}

Dashboard.layout = {
    breadcrumbs: [
        {
            title: 'Dashboard',
            href: dashboard(),
        },
    ],
};
