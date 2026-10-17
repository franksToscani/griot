import { Link, router } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
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

type Props = {
    filters: Filters;
    options: ProviderFilterOptions;
};

export function hasActiveFilters(filters: Filters): boolean {
    return filters.category !== null || filters.city !== null;
}

export function ProviderFilters({ filters, options }: Props) {
    const { t } = useTranslation();

    // A new filter value starts again from page 1: the URL has no "page".
    const apply = (key: keyof Filters, value: string) => {
        router.visit(
            index({
                query: { ...filters, [key]: value === ALL ? null : value },
            }),
            { preserveScroll: true },
        );
    };

    return (
        <div className="flex flex-wrap items-end gap-4">
            <div className="grid gap-2">
                <Label htmlFor="filter-category">
                    {t('providers.filters.category')}
                </Label>
                <Select
                    value={filters.category ?? ALL}
                    onValueChange={(value) => apply('category', value)}
                >
                    <SelectTrigger id="filter-category" className="w-56">
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
                    onValueChange={(value) => apply('city', value)}
                >
                    <SelectTrigger id="filter-city" className="w-56">
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
