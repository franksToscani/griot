import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useTranslation } from '@/hooks/use-translation';
import { HERO } from '@/lib/home-hero';
import { cn } from '@/lib/utils';
import { index } from '@/routes/providers';

// The same limit as ProviderIndexRequest::SEARCH_MAX: a longer search would be ignored.
const SEARCH_MAX = 100;

// The search of the band at the top of the home page (docs/home.md, docs/search.md): a plain HTML
// form, sent with GET to the list of providers, which reads q and city as its own search and
// filter. It works without JavaScript, and needs nothing else. The city is a native <select>:
// Radix's one needs JavaScript and a hidden field to be sent with the form. An empty field sends
// an empty value, which the list ignores.
export function HomeSearch({ cities }: { cities: string[] }) {
    const { t } = useTranslation();

    return (
        <form
            role="search"
            method="get"
            action={index().url}
            className="flex flex-col gap-4 md:flex-row md:flex-wrap md:items-end"
        >
            <div className="grid min-w-0 gap-2 md:flex-1">
                <Label htmlFor="home-search-q">{t('home.search.q')}</Label>
                <Input
                    id="home-search-q"
                    type="search"
                    name="q"
                    maxLength={SEARCH_MAX}
                    className={HERO.field}
                />
            </div>
            <div className="grid min-w-0 gap-2">
                <Label htmlFor="home-search-city">
                    {t('home.search.city')}
                </Label>
                {/* The same look as the kit's fields: border, height (44px on phones), focus. */}
                <select
                    id="home-search-city"
                    name="city"
                    defaultValue=""
                    className={cn(
                        'h-9 w-full min-w-0 rounded-md border border-input px-3 text-base shadow-xs outline-none focus-visible:ring-[3px] focus-visible:outline-hidden max-md:min-h-11 md:text-sm',
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
            <Button type="submit" className={cn('max-md:w-full', HERO.button)}>
                {t('home.search.submit')}
            </Button>
        </form>
    );
}
