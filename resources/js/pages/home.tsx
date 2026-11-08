import { Head } from '@inertiajs/react';
import { CalendarCheck, MailCheck, Search } from 'lucide-react';
import { CategoryCard } from '@/components/home/category-card';
import { FeaturedProviderCard } from '@/components/home/featured-provider-card';
import { HomeHero } from '@/components/home/home-hero';
import { ProfessionalsBand } from '@/components/home/professionals-band';
import { ScrollRow } from '@/components/home/scroll-row';
import { useReveal } from '@/hooks/use-reveal';
import { useTranslation } from '@/hooks/use-translation';
import { buildSlides } from '@/lib/home-carousel';
import { categoryCards } from '@/lib/home-categories';
import { HOME_PHOTOS } from '@/lib/home-photos';
import { revealDelay } from '@/lib/motion';
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

// The icon of each step: decorative, the step says it in words.
const STEP_ICONS = {
    choose: Search,
    book: CalendarCheck,
    confirm: MailCheck,
} as const;

// The home page for the customers (issue #144, docs/home.md): the band with the search and the
// carousel, then the categories, the featured providers, how it works and the band for the
// professionals. Headings go h1 (in HomeHero), h2 for each section, h3 for each step and each
// featured provider. The public layout
// leaves the width to this page: the band goes from edge to edge, the sections below are in the
// site's container.
export default function Home({ categories, featured, cities }: Props) {
    const { t } = useTranslation();
    // One slide per category with a photo; none: the band has one column.
    const slides = buildSlides(t, categories, HOME_PHOTOS);
    // The steps appear one after the other when they are scrolled to.
    const steps = useReveal<HTMLOListElement>();

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
                    <div className="flex flex-col gap-2">
                        <h2 className="text-xl font-semibold">
                            {t('home.steps.title')}
                        </h2>
                        {/* A short orange bar under the title: decorative, no text on it, no
                            meaning. Gone in forced colours, where it would say nothing. */}
                        <span
                            aria-hidden="true"
                            className="block h-1 w-12 rounded-full bg-brand forced-colors:hidden"
                        />
                    </div>
                    <ol ref={steps} className="grid gap-4 md:grid-cols-3">
                        {STEPS.map((step, index) => {
                            const Icon = STEP_ICONS[step];

                            return (
                                <li
                                    key={step}
                                    className="reveal-item flex flex-col gap-3 rounded-xl border bg-card p-4 text-card-foreground"
                                    style={revealDelay(index)}
                                >
                                    {/* The icon in an amber circle: decorative. In forced
                                        colours the circle keeps a border. */}
                                    <span
                                        aria-hidden="true"
                                        className="flex size-12 items-center justify-center rounded-full bg-highlight text-highlight-foreground forced-colors:border"
                                    >
                                        <Icon className="size-6" />
                                    </span>
                                    {/* The number of the step is in the heading: "Passo 1". */}
                                    <h3 className="font-medium">
                                        <span className="block text-sm font-semibold text-muted-foreground">
                                            {t('home.steps.number', {
                                                number: index + 1,
                                            })}
                                        </span>
                                        {t(`home.steps.${step}.title`)}
                                    </h3>
                                    <p className="text-sm text-muted-foreground">
                                        {t(`home.steps.${step}.text`)}
                                    </p>
                                </li>
                            );
                        })}
                    </ol>
                </section>

                {/* Only with a contact of the site (config/contact.php): otherwise no band. */}
                <ProfessionalsBand />
            </div>
        </>
    );
}

// The categories, in the shared row that scrolls sideways (ScrollRow): a card each, with its photo
// or a coloured tile, in the order of the prop.
function CategoriesSection({ categories }: { categories: Category[] }) {
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
