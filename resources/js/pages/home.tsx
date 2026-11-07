import { Head, Link } from '@inertiajs/react';
import { FeaturedProviderCard } from '@/components/home/featured-provider-card';
import { HomeHero } from '@/components/home/home-hero';
import { ScrollRow } from '@/components/home/scroll-row';
import { useTranslation } from '@/hooks/use-translation';
import { buildSlides } from '@/lib/home-carousel';
import { HOME_PHOTOS } from '@/lib/home-photos';
import { touchTarget } from '@/lib/touch-target';
import { cn } from '@/lib/utils';
import { index } from '@/routes/providers';
import type { Category, FeaturedProvider } from '@/types';

type Props = {
    // The categories with something to book, sorted on their translated name.
    categories: Category[];
    // "Professionisti in evidenza": at most 8, the most recently published first.
    featured: FeaturedProvider[];
    // The cities of the published providers, for the search in the band at the top.
    cities: string[];
};

const CATEGORIES_TITLE_ID = 'home-categories-title';
const FEATURED_TITLE_ID = 'home-featured-title';

// The three steps of "Come funziona", in their order: the keys of their texts.
const STEPS = ['choose', 'book', 'confirm'] as const;

// The home page for the customers (issue #144, docs/home.md): the band with the search and the
// carousel, then the categories, the featured providers and how it works. Headings go h1 (in
// HomeHero), h2 for each section, h3 for each step and each featured provider. The public layout
// leaves the width to this page: the band goes from edge to edge, the sections below are in the
// site's container.
export default function Home({ categories, featured, cities }: Props) {
    const { t } = useTranslation();
    // One slide per category with a photo; none: the band has one column.
    const slides = buildSlides(t, categories, HOME_PHOTOS);

    return (
        <>
            <Head title={t('home.title')} />
            <HomeHero cities={cities} slides={slides} />
            <div className="mx-auto flex w-full max-w-6xl flex-col gap-10 px-4 py-10">
                {/* No categories with something to book: no section, rather than an empty one. */}
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

                <section className="flex flex-col gap-4">
                    <h2 className="text-xl font-semibold">
                        {t('home.steps.title')}
                    </h2>
                    <ol className="grid gap-4 md:grid-cols-3">
                        {STEPS.map((step) => (
                            <li
                                key={step}
                                className="flex flex-col gap-2 rounded-xl border bg-card p-4 text-card-foreground"
                            >
                                <h3 className="font-medium">
                                    {t(`home.steps.${step}.title`)}
                                </h3>
                                <p className="text-sm text-muted-foreground">
                                    {t(`home.steps.${step}.text`)}
                                </p>
                            </li>
                        ))}
                    </ol>
                </section>
            </div>
        </>
    );
}

// The categories, in the shared row that scrolls sideways (ScrollRow).
function CategoriesSection({ categories }: { categories: Category[] }) {
    const { t } = useTranslation();

    return (
        <ScrollRow
            titleId={CATEGORIES_TITLE_ID}
            title={t('home.categories.title')}
            items={categories}
            renderItem={(category) => (
                // The list already filtered on the category, as the filter does.
                <Link
                    href={index({
                        query: { category: category.slug },
                    })}
                    className={cn(
                        'flex min-h-20 min-w-36 items-center justify-center rounded-xl border bg-card px-4 py-3 text-center font-medium text-card-foreground outline-none hover:bg-accent focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-hidden',
                        touchTarget,
                    )}
                >
                    {category.name}
                </Link>
            )}
        />
    );
}
