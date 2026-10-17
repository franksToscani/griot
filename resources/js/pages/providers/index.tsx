import { Head, Link } from '@inertiajs/react';
import { Pagination } from '@/components/pagination';
import { ProviderCard } from '@/components/provider-card';
import {
    hasActiveFilters,
    ProviderFilters,
} from '@/components/provider-filters';
import { Button } from '@/components/ui/button';
import { useTranslation } from '@/hooks/use-translation';
import { index } from '@/routes/providers';
import type {
    Paginated,
    ProviderFilterOptions,
    ProviderFilters as Filters,
    ProviderListItem,
} from '@/types';

type Props = {
    providers: Paginated<ProviderListItem>;
    filters: Filters;
    filterOptions: ProviderFilterOptions;
};

export default function ProvidersIndex({
    providers,
    filters,
    filterOptions,
}: Props) {
    const { t } = useTranslation();
    const filtered = hasActiveFilters(filters);

    return (
        <>
            <Head title={t('providers.list.title')} />
            <div className="flex flex-col gap-6">
                <h1 className="text-2xl font-semibold">
                    {t('providers.list.title')}
                </h1>

                <ProviderFilters filters={filters} options={filterOptions} />

                {providers.data.length === 0 ? (
                    <div className="flex flex-col items-start gap-3">
                        <p className="text-muted-foreground">
                            {filtered
                                ? t('providers.list.empty_filtered')
                                : t('providers.list.empty')}
                        </p>
                        {filtered && (
                            <Button variant="outline" asChild>
                                <Link href={index()}>
                                    {t('providers.filters.reset')}
                                </Link>
                            </Button>
                        )}
                    </div>
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
