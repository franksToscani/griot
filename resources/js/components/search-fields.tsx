import { Search } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useTranslation } from '@/hooks/use-translation';
import { cn } from '@/lib/utils';

// The same limit as ProviderIndexRequest::SEARCH_MAX: a longer search would be ignored.
const SEARCH_MAX = 100;

// The look of the fields, given by the form that holds them: the band of the home page has its
// own colours (HERO), the list the kit's.
export type SearchFieldsLook = {
    // Height and text size of the fields and of the button.
    size: string;
    field: string;
    button: string;
};

type Props = {
    // The start of the ids: "home-search" gives "home-search-q" and "home-search-city".
    idPrefix: string;
    cities: string[];
    defaultQ?: string;
    defaultCity?: string;
    look: SearchFieldsLook;
};

// The fields of the search by service and city (docs/search.md), without their form: the home
// page puts them in a plain HTML form, the list in a form sent as an Inertia visit. A text field
// q with a decorative magnifying glass and an example (the label stays visible above it), a
// native <select> city ("Tutte le città" sends an empty value, which the list ignores) and
// "Cerca". From 768 px they share a row that wraps when it is narrow; under 768 px one column.
export function SearchFields({
    idPrefix,
    cities,
    defaultQ = '',
    defaultCity = '',
    look,
}: Props) {
    const { t } = useTranslation();
    const qId = `${idPrefix}-q`;
    const cityId = `${idPrefix}-city`;

    return (
        <>
            <div className="grid min-w-0 gap-2 md:min-w-56 md:flex-1">
                <Label htmlFor={qId}>{t('search.q')}</Label>
                {/* The magnifying glass in the field, decorative: the label says what it is for. */}
                <div className="relative">
                    <Search
                        aria-hidden="true"
                        className="pointer-events-none absolute inset-y-0 left-3 my-auto size-5 text-muted-foreground"
                    />
                    <Input
                        id={qId}
                        type="search"
                        name="q"
                        maxLength={SEARCH_MAX}
                        defaultValue={defaultQ}
                        placeholder={t('search.placeholder')}
                        className={cn('pl-10', look.size, look.field)}
                    />
                </div>
            </div>
            <div className="grid min-w-0 gap-2 md:w-44">
                <Label htmlFor={cityId}>{t('search.city')}</Label>
                {/* The same look as the kit's fields: border, height, focus. */}
                <select
                    id={cityId}
                    name="city"
                    defaultValue={defaultCity}
                    className={cn(
                        'w-full min-w-0 rounded-md border border-input px-3 shadow-xs outline-none focus-visible:ring-[3px] focus-visible:outline-hidden motion-safe:transition-[color,box-shadow] motion-safe:duration-200 max-md:min-h-11',
                        look.size,
                        look.field,
                    )}
                >
                    <option value="">{t('search.all_cities')}</option>
                    {cities.map((city) => (
                        <option key={city} value={city}>
                            {city}
                        </option>
                    ))}
                </select>
            </div>
            <Button
                type="submit"
                className={cn('px-6 max-md:w-full', look.size, look.button)}
            >
                {t('search.submit')}
            </Button>
        </>
    );
}
