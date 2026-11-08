import { Head, Link } from '@inertiajs/react';
import { Pagination } from '@/components/pagination';
import { ProviderCard } from '@/components/provider-card';
import {
    hasActiveFilters,
    ProviderFilters,
    resetFilters,
} from '@/components/provider-filters';
import { ProviderSort } from '@/components/provider-sort';
import { Button } from '@/components/ui/button';
import { useTranslation } from '@/hooks/use-translation';
import type { TranslateFn } from '@/hooks/use-translation';
import type {
    Paginated,
    ProviderFilterOptions,
    ProviderFilters as Filters,
    ProviderListItem,
} from '@/types';

// The text of the results: how many, or the empty state that fits (a search, other filters, none).
function resultsText(t: TranslateFn, total: number, filters: Filters): string {
    if (total === 1) {
        return t('providers.list.results_one');
    }

    if (total > 1) {
        return t('providers.list.results', { count: total });
    }

    if (filters.q !== null) {
        return t('providers.list.empty_search');
    }

    return hasActiveFilters(filters)
        ? t('providers.list.empty_filtered')
        : t('providers.list.empty');
}

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

                <div className="flex flex-wrap items-end justify-between gap-4">
                    {/* Always on the page, so a new search, filter or order changes its text
                        and a screen reader announces it: the number of results, or why there
                        is none. */}
                    <p role="status" className="text-muted-foreground">
                        {resultsText(t, providers.meta.total, filters)}
                    </p>
                    <ProviderSort filters={filters} />
                </div>

                {providers.data.length === 0 ? (
                    filtered && (
                        <div>
                            <Button variant="outline" asChild>
                                {/* The filters go, the order stays. */}
                                <Link href={resetFilters(filters)}>
                                    {t('providers.filters.reset')}
                                </Link>
                            </Button>
                        </div>
                    )
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
