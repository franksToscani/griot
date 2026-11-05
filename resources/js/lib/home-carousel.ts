// The carousel at the top of the home page (issue #144, docs/home.md): the small rules, apart from
// React so that they can be tested with Node. One slide per category with something to book.
import type { TranslateFn } from '@/hooks/use-translation';
import type { Category } from '@/types';

// The colours of the tiles, in turn: green, yellow, light blue, white. Only the tokens of
// resources/css/app.css, each with its own text colour (the classes are in home-carousel.tsx,
// their contrast in AccessibilityContrastTest). Never red: the red is only the thin decor line.
export const SLIDE_TONES = ['primary', 'highlight', 'info', 'card'] as const;

export type SlideTone = (typeof SLIDE_TONES)[number];

// A photo behind the tile, from step 6 of #144: decorative, so no alternative text. null today.
export type SlideImage = {
    src: string;
    srcSet: string;
    width: number;
    height: number;
};

export type Slide = {
    id: number;
    slug: string;
    name: string;
    sentence: string;
    tone: SlideTone;
    image: SlideImage | null;
};

// The slides, in the order of the categories. The sentence is the category's own
// (home.carousel.sentence.<slug>) or, for a category without one, a general sentence: a key that
// is missing comes back from t() as the key itself.
export function buildSlides(t: TranslateFn, categories: Category[]): Slide[] {
    return categories.map((category, index) => {
        const key = `home.carousel.sentence.${category.slug}`;
        const own = t(key);

        return {
            id: category.id,
            slug: category.slug,
            name: category.name,
            sentence: own === key ? t('home.carousel.sentence.default') : own,
            tone: SLIDE_TONES[index % SLIDE_TONES.length],
            image: null,
        };
    });
}

// "Slide 2 di 7": what is announced after a change, and the place of each slide.
export function slidePosition(
    t: TranslateFn,
    index: number,
    total: number,
): string {
    return t('home.carousel.position', { number: index + 1, total });
}
