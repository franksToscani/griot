import { Head, Link } from '@inertiajs/react';
import { HomeIntro } from '@/components/home/home-intro';
import { useTranslation } from '@/hooks/use-translation';
import { touchTarget } from '@/lib/touch-target';
import { cn } from '@/lib/utils';
import { index } from '@/routes/providers';
import type { Category } from '@/types';

type Props = {
    // The categories with something to book, sorted on their translated name.
    categories: Category[];
};

const CATEGORIES_TITLE_ID = 'home-categories-title';

// The three steps of "Come funziona", in their order: the keys of their texts.
const STEPS = ['choose', 'book', 'confirm'] as const;

// The home page for the customers (issue #144, docs/home.md): the opening block, the categories,
// then how it works. Headings go h1 (in HomeIntro), h2 for each section, h3 for each step.
export default function Home({ categories }: Props) {
    const { t } = useTranslation();

    return (
        <>
            <Head title={t('home.title')} />
            <div className="flex flex-col gap-10">
                <HomeIntro />

                {/* No categories with something to book: no section, rather than an empty one. */}
                {categories.length > 0 && (
                    <CategoriesSection categories={categories} />
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

// The categories in a row that scrolls sideways. The row is a region named by the title of the
// section, and takes the focus itself (tabIndex 0): the arrow keys scroll it. Every link stays in
// the order of the Tab key. Only the row scrolls, never the page.
function CategoriesSection({ categories }: { categories: Category[] }) {
    const { t } = useTranslation();

    return (
        <section className="flex min-w-0 flex-col gap-4">
            <h2 id={CATEGORIES_TITLE_ID} className="text-xl font-semibold">
                {t('home.categories.title')}
            </h2>
            <div
                role="region"
                aria-labelledby={CATEGORIES_TITLE_ID}
                tabIndex={0}
                className="overflow-x-auto rounded-xl outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-hidden"
            >
                <ul className="flex snap-x gap-3 p-1 pb-3">
                    {categories.map((category) => (
                        <li key={category.id} className="shrink-0 snap-start">
                            {/* The list already filtered on the category, as the filter does. */}
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
                        </li>
                    ))}
                </ul>
            </div>
        </section>
    );
}
