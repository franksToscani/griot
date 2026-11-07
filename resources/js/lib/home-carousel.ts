// The carousel at the top of the home page (issue #144, docs/home.md): the small rules, apart from
// React so that they can be tested with Node. One slide per category with something to book.
import type { TranslateFn } from '@/hooks/use-translation';
import type { Category, PhotoVariant } from '@/types';

// The colours of the tiles, in turn: green, yellow, light blue, white. Only the tokens of
// resources/css/app.css, each with its own text colour (the classes are in home-carousel.tsx,
// their contrast in AccessibilityContrastTest). Never red: the red is only the thin decor line.
export const SLIDE_TONES = ['primary', 'highlight', 'info', 'card'] as const;

export type SlideTone = (typeof SLIDE_TONES)[number];

// A photo of the home carousel as lib/home-photos.ts lists it: its sizes and the size of the
// largest one.
export type HomePhoto = {
    width: number;
    height: number;
    variants: PhotoVariant[];
};

// The photo of a slide, next to its coloured tile (step 6 of #144): decorative, so no
// alternative text. null for a category without a photo.
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

// The photo of a category, or null when it has none (a new category breaks nothing). srcset with
// every size, smallest first; src the largest up to 1280 px, for a browser without srcset.
export function slideImage(
    slug: string,
    photos: Record<string, HomePhoto>,
): SlideImage | null {
    const photo = Object.hasOwn(photos, slug) ? photos[slug] : undefined;

    if (photo === undefined || photo.variants.length === 0) {
        return null;
    }

    const variants = [...photo.variants].sort((a, b) => a.width - b.width);
    const fallback =
        variants.filter((variant) => variant.width <= 1280).at(-1) ??
        variants[0];

    return {
        src: fallback.url,
        srcSet: variants
            .map((variant) => `${variant.url} ${variant.width}w`)
            .join(', '),
        width: photo.width,
        height: photo.height,
    };
}

// The slides, in the order of the categories. The sentence is the category's own
// (home.carousel.sentence.<slug>) or, for a category without one, a general sentence: a key that
// is missing comes back from t() as the key itself. The photo, if the category has one.
export function buildSlides(
    t: TranslateFn,
    categories: Category[],
    photos: Record<string, HomePhoto> = {},
): Slide[] {
    return categories.map((category, index) => {
        const key = `home.carousel.sentence.${category.slug}`;
        const own = t(key);

        return {
            id: category.id,
            slug: category.slug,
            name: category.name,
            sentence: own === key ? t('home.carousel.sentence.default') : own,
            tone: SLIDE_TONES[index % SLIDE_TONES.length],
            image: slideImage(category.slug, photos),
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
