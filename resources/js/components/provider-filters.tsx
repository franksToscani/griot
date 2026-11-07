import { Link, router } from '@inertiajs/react';
import { useState } from 'react';
import type { FormEvent } from 'react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { useTranslation } from '@/hooks/use-translation';
import { index } from '@/routes/providers';
import type {
    ProviderFilterOptions,
    ProviderFilters as Filters,
} from '@/types';

// Radix Select does not accept an empty value, so "all" has its own value.
const ALL = '__all__';

// The same limit as ProviderIndexRequest::SEARCH_MAX: a longer search would be ignored.
const SEARCH_MAX = 100;

type Props = {
    filters: Filters;
    options: ProviderFilterOptions;
};

export function hasActiveFilters(filters: Filters): boolean {
    return (
        filters.category !== null || filters.city !== null || filters.q !== null
    );
}

export function ProviderFilters({ filters, options }: Props) {
    const { t } = useTranslation();

    // The text of the field. It follows the search of the page when that changes from outside
    // the field (a link, the reset, the browser's back button): the server's value, trimmed.
    const [text, setText] = useState(filters.q ?? '');
    const [shownSearch, setShownSearch] = useState(filters.q);

    if (filters.q !== shownSearch) {
        setShownSearch(filters.q);
        setText(filters.q ?? '');
    }

    // A new filter value starts again from page 1: the URL has no "page". preserveState keeps
    // the page mounted, so the role="status" of the list changes and is announced.
    const apply = (key: keyof Filters, value: string | null) => {
        router.visit(index({ query: { ...filters, [key]: value } }), {
            preserveScroll: true,
            preserveState: true,
        });
    };

    // A GET form: without JavaScript the browser sends q with the other filters, from the hidden
    // fields. With JavaScript the same address, as an Inertia visit.
    const search = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        apply('q', text.trim() === '' ? null : text.trim());
    };

    return (
        <div className="flex flex-wrap items-end gap-4">
            <form
                role="search"
                method="get"
                action={index().url}
                onSubmit={search}
                className="flex flex-wrap items-end gap-2"
            >
                {filters.category !== null && (
                    <input
                        type="hidden"
                        name="category"
                        value={filters.category}
                    />
                )}
                {filters.city !== null && (
                    <input type="hidden" name="city" value={filters.city} />
                )}
                <div className="grid gap-2">
                    <Label htmlFor="filter-search">
                        {t('providers.filters.search')}
                    </Label>
                    <Input
                        id="filter-search"
                        type="search"
                        name="q"
                        maxLength={SEARCH_MAX}
                        value={text}
                        onChange={(event) => setText(event.target.value)}
                        className="max-w-full min-w-56"
                    />
                </div>
                <Button type="submit">
                    {t('providers.filters.search_submit')}
                </Button>
            </form>

            <div className="grid gap-2">
                <Label htmlFor="filter-category">
                    {t('providers.filters.category')}
                </Label>
                <Select
                    value={filters.category ?? ALL}
                    onValueChange={(value) =>
                        apply('category', value === ALL ? null : value)
                    }
                >
                    <SelectTrigger
                        id="filter-category"
                        className="max-w-full min-w-56"
                    >
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value={ALL}>
                            {t('providers.filters.all_categories')}
                        </SelectItem>
                        {options.categories.map((category) => (
                            <SelectItem key={category.id} value={category.slug}>
                                {category.name}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>
            </div>

            <div className="grid gap-2">
                <Label htmlFor="filter-city">
                    {t('providers.filters.city')}
                </Label>
                <Select
                    value={filters.city ?? ALL}
                    onValueChange={(value) =>
                        apply('city', value === ALL ? null : value)
                    }
                >
                    <SelectTrigger
                        id="filter-city"
                        className="max-w-full min-w-56"
                    >
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value={ALL}>
                            {t('providers.filters.all_cities')}
                        </SelectItem>
                        {options.cities.map((city) => (
                            <SelectItem key={city} value={city}>
                                {city}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>
            </div>

            {hasActiveFilters(filters) && (
                <Button variant="outline" asChild>
                    <Link href={index()} preserveScroll>
                        {t('providers.filters.reset')}
                    </Link>
                </Button>
            )}
        </div>
    );
}
