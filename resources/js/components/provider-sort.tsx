import { router } from '@inertiajs/react';
import { Label } from '@/components/ui/label';
import { useTranslation } from '@/hooks/use-translation';
import { index } from '@/routes/providers';
import type { ProviderFilters, ProviderSort as Sort } from '@/types';

// The orders of ProviderIndexRequest::SORTS, in this order in the list.
const SORTS: Sort[] = ['name', 'recent', 'price_asc', 'price_desc'];

// The same look as the other native selects of the site, 44px on phones.
const selectClass =
    'border-input flex h-9 w-full min-w-0 rounded-md border bg-transparent px-3 py-1 text-base shadow-xs outline-none focus-visible:outline-hidden focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 max-md:min-h-11 md:text-sm';

// "Ordina per" of the public list (docs/search.md): a native select with its visible label. A
// change visits the list again with the same filters, from page 1 (the address has no "page"),
// keeping the page mounted as the filters do, so the number of results is announced again. The
// default order (by name) leaves sort out of the address.
export function ProviderSort({ filters }: { filters: ProviderFilters }) {
    const { t } = useTranslation();

    const change = (value: Sort) => {
        router.visit(
            index({
                query: { ...filters, sort: value === 'name' ? null : value },
            }),
            { preserveScroll: true, preserveState: true },
        );
    };

    return (
        <div className="grid gap-2">
            <Label htmlFor="provider-sort">{t('providers.sort.label')}</Label>
            <select
                id="provider-sort"
                name="sort"
                value={filters.sort ?? 'name'}
                onChange={(event) => change(event.target.value as Sort)}
                className={selectClass}
            >
                {SORTS.map((sort) => (
                    <option key={sort} value={sort}>
                        {t(`providers.sort.${sort}`)}
                    </option>
                ))}
            </select>
        </div>
    );
}
