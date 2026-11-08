import { useState } from 'react';
import type { FormEvent } from 'react';
import { activePanelFilters, visitList } from '@/components/provider-filters';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
    SheetTrigger,
} from '@/components/ui/sheet';
import { useTranslation } from '@/hooks/use-translation';
import { index } from '@/routes/providers';
import type {
    Category,
    ProviderFilterKeys,
    ProviderFilters as Filters,
} from '@/types';

// The same look as the other native selects of the site, 44px on phones.
const selectClass =
    'border-input flex h-9 w-full min-w-0 rounded-md border bg-transparent px-3 py-1 text-base shadow-xs outline-none focus-visible:outline-hidden focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 max-md:min-h-11 md:text-sm';

// The panel of the filters of the list (docs/providers-list.md), at every width: the kit's Sheet
// (Radix Dialog), which keeps the focus inside while open, closes with Esc and gives the focus
// back to the button "Filtri" when it closes. Its title and description name it. Inside: the
// category, the maximum price and "solo con foto", each with its visible label. The fields are a
// draft: "Applica" visits the list with them and closes; "Azzera" empties the fields only.
export function ProviderFiltersPanel({
    filters,
    keys,
    categories,
}: {
    filters: Filters;
    keys: ProviderFilterKeys;
    categories: Category[];
}) {
    const { t } = useTranslation();
    const [open, setOpen] = useState(false);
    const [category, setCategory] = useState('');
    const [maxPrice, setMaxPrice] = useState('');
    const [withPhoto, setWithPhoto] = useState(false);
    const count = activePanelFilters(filters, keys);

    // Opening starts again from the filters of the page.
    const changeOpen = (next: boolean) => {
        if (next) {
            setCategory(filters.category ?? '');
            setMaxPrice(filters.max_price ?? '');
            setWithPhoto(filters.with_photo !== null);
        }

        setOpen(next);
    };

    const apply = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        visitList(filters, {
            category: category === '' ? null : category,
            max_price: maxPrice.trim() === '' ? null : maxPrice.trim(),
            with_photo: withPhoto ? '1' : null,
        });
        setOpen(false);
    };

    const clear = () => {
        setCategory('');
        setMaxPrice('');
        setWithPhoto(false);
    };

    return (
        <Sheet open={open} onOpenChange={changeOpen}>
            <SheetTrigger asChild>
                <Button type="button" variant="outline">
                    {count > 0
                        ? t('providers.panel.open_count', { count })
                        : t('providers.panel.open')}
                </Button>
            </SheetTrigger>
            <SheetContent side="right" className="overflow-y-auto">
                <SheetHeader>
                    <SheetTitle>{t('providers.panel.title')}</SheetTitle>
                    <SheetDescription>
                        {t('providers.panel.description')}
                    </SheetDescription>
                </SheetHeader>
                {/* A GET form too: the browser could send it as it is, with the search, the
                    city and the order in hidden fields. */}
                <form
                    method="get"
                    action={index().url}
                    onSubmit={apply}
                    className="flex flex-col gap-6 px-4 pb-4"
                >
                    {filters.q !== null && (
                        <input type="hidden" name="q" value={filters.q} />
                    )}
                    {filters.city !== null && (
                        <input type="hidden" name="city" value={filters.city} />
                    )}
                    {filters.sort !== null && (
                        <input type="hidden" name="sort" value={filters.sort} />
                    )}
                    <div className="grid gap-2">
                        <Label htmlFor="panel-category">
                            {t('providers.filters.category')}
                        </Label>
                        <select
                            id="panel-category"
                            name="category"
                            value={category}
                            onChange={(event) =>
                                setCategory(event.target.value)
                            }
                            className={selectClass}
                        >
                            <option value="">
                                {t('providers.filters.all_categories')}
                            </option>
                            {categories.map((option) => (
                                <option key={option.id} value={option.slug}>
                                    {option.name}
                                </option>
                            ))}
                        </select>
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="panel-max-price">
                            {t('providers.panel.max_price')}
                        </Label>
                        <Input
                            id="panel-max-price"
                            name="max_price"
                            type="text"
                            inputMode="decimal"
                            autoComplete="off"
                            value={maxPrice}
                            onChange={(event) =>
                                setMaxPrice(event.target.value)
                            }
                            aria-describedby="panel-max-price-help"
                        />
                        <p
                            id="panel-max-price-help"
                            className="text-sm text-muted-foreground"
                        >
                            {t('providers.panel.max_price_help')}
                        </p>
                    </div>
                    <div className="flex items-center gap-3">
                        <Checkbox
                            id="panel-with-photo"
                            name="with_photo"
                            value="1"
                            checked={withPhoto}
                            onCheckedChange={(checked) =>
                                setWithPhoto(checked === true)
                            }
                        />
                        <Label htmlFor="panel-with-photo">
                            {t('providers.panel.with_photo')}
                        </Label>
                    </div>
                    <div className="flex flex-wrap gap-2">
                        <Button type="submit">
                            {t('providers.panel.apply')}
                        </Button>
                        <Button type="button" variant="outline" onClick={clear}>
                            {t('providers.panel.clear')}
                        </Button>
                    </div>
                </form>
            </SheetContent>
        </Sheet>
    );
}
