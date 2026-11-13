import { Head } from '@inertiajs/react';
import { CategoryCard } from '@/components/home/category-card';
import { FeaturedProviderCard } from '@/components/home/featured-provider-card';
import { HomeFloatingActions } from '@/components/home/floating-actions';
import { HomeHero } from '@/components/home/home-hero';
import { HowItWorks } from '@/components/home/how-it-works';
import { ProfessionalsBand } from '@/components/home/professionals-band';
import { ScrollRow } from '@/components/home/scroll-row';
import { useTranslation } from '@/hooks/use-translation';
import { buildSlides } from '@/lib/home-carousel';
import { categoryCards } from '@/lib/home-categories';
import { HOME_PHOTOS } from '@/lib/home-photos';
import type { FeaturedProvider, HomeCategory } from '@/types';

type Props = {
    // Every category: the offered ones (something to book) first, then the others, each group
    // sorted on the translated name.
    categories: HomeCategory[];
    // "Professionisti in evidenza": at most 8, the most recently published first.
    featured: FeaturedProvider[];
    // The cities of the published providers, for the search in the band at the top.
    cities: string[];
};

const CATEGORIES_TITLE_ID = 'home-categories-title';
const FEATURED_TITLE_ID = 'home-featured-title';

// The home page for the customers (issue #144, docs/home.md): the band with the search and the
// carousel, then the categories, the featured providers, how it works and the band for the
// professionals; at the bottom right "torna in cima" and the help. Headings go h1 (in HomeHero),
// h2 for each section, h3 for each step and each featured provider. The public layout leaves the
// width to this page: the band goes from edge to edge, the sections below are in the site's
// container.
export default function Home({ categories, featured, cities }: Props) {
    const { t } = useTranslation();
    // One slide per offered category with a photo; none: the band has one column. A category
    // without anything to book has no slide: its "Vedi i professionisti" would lead nowhere.
    const slides = buildSlides(
        t,
        categories.filter((category) => category.offered),
        HOME_PHOTOS,
    );

    return (
        <>
            <Head title={t('home.title')} />
            <HomeHero cities={cities} slides={slides} />
            <div className="mx-auto flex w-full max-w-6xl flex-col gap-10 px-4 py-10">
                {/* Every category, even before the first professional: the offered ones are
                    links, the others say "In arrivo". No category at all: no section. */}
                {categories.length > 0 && (
                    <CategoriesSection categories={categories} />
                )}

                {/* Nobody to feature: no section either. */}
                {featured.length > 0 && (
                    <ScrollRow
                        titleId={FEATURED_TITLE_ID}
                        title={t('home.featured.title')}
                        items={featured}
                        renderItem={(provider) => (
                            <FeaturedProviderCard provider={provider} />
                        )}
                    />
                )}

                <HowItWorks />

                {/* Only with a contact of the site (config/contact.php): otherwise no band. */}
                <ProfessionalsBand />
            </div>
            <HomeFloatingActions />
        </>
    );
}

// The categories, in the shared row that scrolls sideways (ScrollRow): a card each, with its photo
// or a coloured tile, in the order of the prop.
function CategoriesSection({ categories }: { categories: HomeCategory[] }) {
    const { t } = useTranslation();

    return (
        <ScrollRow
            titleId={CATEGORIES_TITLE_ID}
            title={t('home.categories.title')}
            items={categoryCards(categories, HOME_PHOTOS)}
            renderItem={(card) => <CategoryCard card={card} />}
        />
    );
}
