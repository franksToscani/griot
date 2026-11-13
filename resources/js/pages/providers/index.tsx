import { Head, Link } from '@inertiajs/react';
import { Pagination } from '@/components/pagination';
import { ProfessionalsInvite } from '@/components/professionals-invite';
import { ProviderCard } from '@/components/provider-card';
import {
    hasActiveFilters,
    ProviderSearchBar,
    resetFilters,
} from '@/components/provider-filters';
import { ProviderFiltersPanel } from '@/components/provider-filters-panel';
import { ProviderSort } from '@/components/provider-sort';
import { ResultsLayout } from '@/components/results-layout';
import { Button } from '@/components/ui/button';
import { useTranslation } from '@/hooks/use-translation';
import type { TranslateFn } from '@/hooks/use-translation';
import type {
    Paginated,
    ProviderFilterKeys,
    ProviderFilterOptions,
    ProviderFilters as Filters,
    ProviderListItem,
} from '@/types';

// The text of the results: how many, or the empty state that fits (a search, other filters, none).
function resultsText(
    t: TranslateFn,
    total: number,
    filters: Filters,
    filtered: boolean,
): string {
    if (total === 1) {
        return t('providers.list.results_one');
    }

    if (total > 1) {
        return t('providers.list.results', { count: total });
    }

    if (filters.q !== null) {
        return t('providers.list.empty_search');
    }

    return filtered
        ? t('providers.list.empty_filtered')
        : t('providers.list.empty');
}

type Props = {
    providers: Paginated<ProviderListItem>;
    filters: Filters;
    filterKeys: ProviderFilterKeys;
    filterOptions: ProviderFilterOptions;
};

// The public list of providers (docs/providers-list.md): the search bar, then a row with
// "Filtri (n)", "Ordina per" and the number of results, then the cards in ResultsLayout (one
// column today; the map will be its aside). The page has the whole width (FULL_WIDTH_PAGES) and
// keeps its content in the site's container.
export default function ProvidersIndex({
    providers,
    filters,
    filterKeys,
    filterOptions,
}: Props) {
    const { t } = useTranslation();
    const filtered = hasActiveFilters(filters, filterKeys.all);

    return (
        <>
            <Head title={t('providers.list.title')} />
            <div className="mx-auto flex w-full max-w-6xl flex-col gap-6 px-4 py-6">
                <h1 className="text-2xl font-semibold">
                    {t('providers.list.title')}
                </h1>

                <ProviderSearchBar
                    filters={filters}
                    keys={filterKeys}
                    cities={filterOptions.cities}
                />

                <div className="flex flex-wrap items-end gap-4">
                    <ProviderFiltersPanel
                        filters={filters}
                        keys={filterKeys}
                        categories={filterOptions.categories}
                    />
                    <ProviderSort filters={filters} />
                    {/* Always on the page, so a new search, filter or order changes its text
                        and a screen reader announces it: the number of results, or why there
                        is none. */}
                    <p role="status" className="text-muted-foreground">
                        {resultsText(
                            t,
                            providers.meta.total,
                            filters,
                            filtered,
                        )}
                        {/* Nobody yet, without a search or a filter: a new site. */}
                        <ProfessionalsInvite
                            empty={
                                providers.meta.total === 0 &&
                                !filtered &&
                                filters.q === null
                            }
                        />
                    </p>
                </div>

                {/* The filters go, the order stays. */}
                {filtered && (
                    <div>
                        <Button variant="outline" asChild>
                            <Link href={resetFilters(filters)} preserveScroll>
                                {t('providers.filters.reset')}
                            </Link>
                        </Button>
                    </div>
                )}

                <ResultsLayout>
                    {providers.data.length > 0 && (
                        <ul className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                            {providers.data.map((provider) => (
                                <li key={provider.id}>
                                    <ProviderCard provider={provider} />
                                </li>
                            ))}
                        </ul>
                    )}
                </ResultsLayout>

                <Pagination links={providers.links} meta={providers.meta} />
            </div>
        </>
    );
}
