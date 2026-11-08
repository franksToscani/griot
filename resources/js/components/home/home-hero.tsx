import { useEffect, useState } from 'react';
import { HomeCarousel } from '@/components/home/home-carousel';
import { HomeSearch } from '@/components/home/home-search';
import { useTranslation } from '@/hooks/use-translation';
import type { Slide } from '@/lib/home-carousel';
import { HERO } from '@/lib/home-hero';
import { cn } from '@/lib/utils';

// The band at the top of the home page (docs/home.md): from edge to edge, dark brown, square
// corners; its content in the site's container. On the left the page's one h1, a sentence and
// the search; on the right the carousel, under them on phones. Its height and its vertical space
// in rem, so that it grows with the zoom; the text sets it, never a set height.
// At the first visit of the page (a page loaded by the browser, not a visit inside the site) the
// title, the sentence and the search come in with a short rise (app.css, data-home-enter, only
// without "reduce motion"); the carousel and its photo do not move.

// Whether the band has already been shown since the browser loaded the site.
let shownOnce = false;

export function HomeHero({
    cities,
    slides,
}: {
    cities: string[];
    slides: Slide[];
}) {
    const { t } = useTranslation();
    const [enter] = useState(() => !shownOnce);

    useEffect(() => {
        shownOnce = true;
    }, []);

    return (
        <div className={HERO.band}>
            <div
                className={cn(
                    'mx-auto grid w-full max-w-6xl gap-10 px-4 py-12 md:min-h-[32rem] md:items-center md:py-16 lg:py-20',
                    slides.length > 0 && 'md:grid-cols-2',
                )}
            >
                <div
                    className="flex min-w-0 flex-col gap-5"
                    data-home-enter={enter ? '' : undefined}
                >
                    {/* Bigger from 1024 px; balanced lines and a measure of its own, so that a
                        longer title (French) never leaves one word alone on a line. */}
                    <h1 className="max-w-2xl text-3xl leading-tight font-semibold text-balance break-words md:text-4xl lg:text-5xl">
                        {t('home.intro.title')}
                    </h1>
                    <p className="max-w-xl md:text-lg">
                        {t('home.intro.text')}
                    </p>
                    <HomeSearch cities={cities} />
                </div>
                <HomeCarousel slides={slides} />
            </div>
        </div>
    );
}
