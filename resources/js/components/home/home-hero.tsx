import { HomeCarousel } from '@/components/home/home-carousel';
import { HomeSearch } from '@/components/home/home-search';
import { useTranslation } from '@/hooks/use-translation';
import type { Slide } from '@/lib/home-carousel';
import { HERO } from '@/lib/home-hero';
import { cn } from '@/lib/utils';

// The band at the top of the home page (docs/home.md): from edge to edge, dark green, square
// corners; its content in the site's container. On the left the page's one h1, a sentence and
// the search; on the right the carousel, under them on phones. Its height in rem, so that it
// grows with the zoom; the text sets it, never a set height.
export function HomeHero({
    cities,
    slides,
}: {
    cities: string[];
    slides: Slide[];
}) {
    const { t } = useTranslation();

    return (
        <div className={HERO.band}>
            <div
                className={cn(
                    'mx-auto grid w-full max-w-6xl gap-10 px-4 py-10 md:min-h-[28rem] md:items-center md:py-14',
                    slides.length > 0 && 'md:grid-cols-2',
                )}
            >
                <div className="flex min-w-0 flex-col gap-4">
                    <h1 className="text-3xl font-semibold break-words md:text-4xl">
                        {t('home.intro.title')}
                    </h1>
                    <p className="max-w-xl">{t('home.intro.text')}</p>
                    <HomeSearch cities={cities} />
                </div>
                <HomeCarousel slides={slides} />
            </div>
        </div>
    );
}
