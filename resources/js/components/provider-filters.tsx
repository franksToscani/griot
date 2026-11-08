import { router } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { SearchFields } from '@/components/search-fields';
import { index } from '@/routes/providers';
import type {
    ProviderFilterKey,
    ProviderFilterKeys,
    ProviderFilters as Filters,
} from '@/types';

// The fields of the search bar: the other filters and the order go with it as hidden fields.
const BAR_FIELDS: ProviderFilterKey[] = ['q', 'city'];

// The look of the list's search bar: the kit's fields, 44px on phones, 40px from 768 px.
const LOOK = { size: 'h-11 md:h-10', field: '', button: '' };

// The text of a field of a sent form; empty when it is missing or not text.
function fieldText(data: FormData, name: string): string {
    const value = data.get(name);

    return typeof value === 'string' ? value.trim() : '';
}

// Whether any filter of the list is on (ProviderIndexRequest::FILTERS). The order is not a filter.
export function hasActiveFilters(
    filters: Filters,
    keys: ProviderFilterKey[],
): boolean {
    return keys.some((key) => filters[key] !== null);
}

// How many filters of the panel are on, for "Filtri (n)": not the city nor the search.
export function activePanelFilters(
    filters: Filters,
    keys: ProviderFilterKeys,
): number {
    return keys.panel.filter((key) => filters[key] !== null).length;
}

// The list without filters, in the same order: "Azzera filtri" never resets the order.
export function resetFilters(filters: Filters) {
    return index({ query: { sort: filters.sort } });
}

// A visit of the list with some filters changed, from page 1 (the address has no "page"),
// keeping the page mounted: the status of the results changes and is announced.
export function visitList(filters: Filters, changes: Partial<Filters>) {
    router.visit(index({ query: { ...filters, ...changes } }), {
        preserveScroll: true,
        preserveState: true,
    });
}

// The search bar of the list (docs/search.md): the fields of the home page (SearchFields) in a
// GET form. With JavaScript it is an Inertia visit with the other filters and the order; without,
// the browser sends them from the hidden fields. The fields start again from the server's values
// when those change from outside (a link, "Azzera filtri", the browser's back button).
export function ProviderSearchBar({
    filters,
    keys,
    cities,
}: {
    filters: Filters;
    keys: ProviderFilterKeys;
    cities: string[];
}) {
    const hidden = [
        ...keys.all.filter((key) => !BAR_FIELDS.includes(key)),
        'sort' as const,
    ];

    const search = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        const data = new FormData(event.currentTarget);
        const q = fieldText(data, 'q');
        const city = fieldText(data, 'city');

        visitList(filters, {
            q: q === '' ? null : q,
            city: city === '' ? null : city,
        });
    };

    return (
        <form
            key={`${filters.q ?? ''}|${filters.city ?? ''}`}
            role="search"
            method="get"
            action={index().url}
            onSubmit={search}
            className="flex flex-col gap-4 md:flex-row md:flex-wrap md:items-end"
        >
            {hidden.map((key) => {
                const value = filters[key];

                return (
                    value !== null && (
                        <input
                            key={key}
                            type="hidden"
                            name={key}
                            value={value}
                        />
                    )
                );
            })}
            <SearchFields
                idPrefix="provider-search"
                cities={cities}
                defaultQ={filters.q ?? ''}
                defaultCity={filters.city ?? ''}
                look={LOOK}
            />
        </form>
    );
}
