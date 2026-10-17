import { Head } from '@inertiajs/react';
import { Pagination } from '@/components/pagination';
import { ProviderCard } from '@/components/provider-card';
import { useTranslation } from '@/hooks/use-translation';
import type { Paginated, ProviderListItem } from '@/types';

type Props = {
    providers: Paginated<ProviderListItem>;
};

export default function ProvidersIndex({ providers }: Props) {
    const { t } = useTranslation();

    return (
        <>
            <Head title={t('providers.list.title')} />
            <div className="flex flex-col gap-6">
                <h1 className="text-2xl font-semibold">
                    {t('providers.list.title')}
                </h1>

                {providers.data.length === 0 ? (
                    <p className="text-muted-foreground">
                        {t('providers.list.empty')}
                    </p>
                ) : (
                    <ul className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                        {providers.data.map((provider) => (
                            <li key={provider.id}>
                                <ProviderCard provider={provider} />
                            </li>
                        ))}
                    </ul>
                )}

                <Pagination links={providers.links} meta={providers.meta} />
            </div>
        </>
    );
}
