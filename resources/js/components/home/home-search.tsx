import { Search } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useTranslation } from '@/hooks/use-translation';
import { HERO } from '@/lib/home-hero';
import { cn } from '@/lib/utils';
import { index } from '@/routes/providers';

// The same limit as ProviderIndexRequest::SEARCH_MAX: a longer search would be ignored.
const SEARCH_MAX = 100;

// The height of the fields and of the button: 44px on phones (as the kit), 3rem from 768 px; the
// text at its normal size, never the kit's smaller one from 768 px.
const SIZE = 'h-11 text-base md:h-12 md:text-base';

// The search of the band at the top of the home page (docs/home.md, docs/search.md): a plain HTML
// form, sent with GET to the list of providers, which reads q and city as its own search and
// filter. It works without JavaScript, and needs nothing else. The city is a native <select>:
// Radix's one needs JavaScript and a hidden field to be sent with the form. An empty field sends
// an empty value, which the list ignores. The placeholder is an example, never the label: the
// labels stay visible above the fields. From 768 px the fields and the button share a row, which
// wraps when the column is narrow; under 768 px one column, everything full width.
export function HomeSearch({ cities }: { cities: string[] }) {
    const { t } = useTranslation();

    return (
        <form
            role="search"
            method="get"
            action={index().url}
            className="flex flex-col gap-4 md:flex-row md:flex-wrap md:items-end"
        >
            <div className="grid min-w-0 gap-2 md:min-w-56 md:flex-1">
                <Label htmlFor="home-search-q">{t('home.search.q')}</Label>
                {/* The magnifying glass in the field, decorative: the label says what it is for. */}
                <div className="relative">
                    <Search
                        aria-hidden="true"
                        className="pointer-events-none absolute inset-y-0 left-3 my-auto size-5 text-muted-foreground"
                    />
                    <Input
                        id="home-search-q"
                        type="search"
                        name="q"
                        maxLength={SEARCH_MAX}
                        placeholder={t('home.search.placeholder')}
                        className={cn('pl-10', SIZE, HERO.field)}
                    />
                </div>
            </div>
            <div className="grid min-w-0 gap-2 md:w-44">
                <Label htmlFor="home-search-city">
                    {t('home.search.city')}
                </Label>
                {/* The same look as the kit's fields: border, height, focus. */}
                <select
                    id="home-search-city"
                    name="city"
                    defaultValue=""
                    className={cn(
                        'w-full min-w-0 rounded-md border border-input px-3 shadow-xs outline-none focus-visible:ring-[3px] focus-visible:outline-hidden max-md:min-h-11',
                        SIZE,
                        HERO.field,
                    )}
                >
                    <option value="">{t('home.search.all_cities')}</option>
                    {cities.map((city) => (
                        <option key={city} value={city}>
                            {city}
                        </option>
                    ))}
                </select>
            </div>
            <Button
                type="submit"
                className={cn('px-6 max-md:w-full', SIZE, HERO.button)}
            >
                {t('home.search.submit')}
            </Button>
        </form>
    );
}
