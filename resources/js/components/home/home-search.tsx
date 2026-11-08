import { SearchFields } from '@/components/search-fields';
import { HERO } from '@/lib/home-hero';
import { index } from '@/routes/providers';

// The height of the fields and of the button: 44px on phones (as the kit), 3rem from 768 px; the
// text at its normal size, never the kit's smaller one from 768 px.
const SIZE = 'h-11 text-base md:h-12 md:text-base';

// The search of the band at the top of the home page (docs/home.md, docs/search.md): a plain HTML
// form, sent with GET to the list of providers, which reads q and city as its own search and
// filter. It works without JavaScript, and needs nothing else: no handler, no Inertia visit. The
// fields are those of the list (SearchFields), in the band's colours.
export function HomeSearch({ cities }: { cities: string[] }) {
    return (
        <form
            role="search"
            method="get"
            action={index().url}
            className="flex flex-col gap-4 md:flex-row md:flex-wrap md:items-end"
        >
            <SearchFields
                idPrefix="home-search"
                cities={cities}
                look={{ size: SIZE, field: HERO.field, button: HERO.button }}
            />
        </form>
    );
}
