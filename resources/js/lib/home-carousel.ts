// The carousel in the band at the top of the home page (docs/home.md): the small rules, apart from
// React so that they can be tested with Node. One slide per category with something to book and
// a photo: the photo is the slide.
import type { TranslateFn } from '@/hooks/use-translation';
import type { Category, PhotoVariant } from '@/types';

// A photo of the home carousel as lib/home-photos.ts lists it: its sizes and the size of the
// largest one.
export type HomePhoto = {
    width: number;
    height: number;
    variants: PhotoVariant[];
};

// The photo of a slide, with the text under it: decorative, so no alternative text.
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
    image: SlideImage;
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

// The slides, in the order of the categories, only those with a photo (a category without one is
// left out, and still has its link in the row of the categories). The sentence is the category's
// own (home.carousel.sentence.<slug>) or, for a category without one, a general sentence: a key
// that is missing comes back from t() as the key itself.
export function buildSlides(
    t: TranslateFn,
    categories: Category[],
    photos: Record<string, HomePhoto> = {},
): Slide[] {
    return categories.flatMap((category) => {
        const image = slideImage(category.slug, photos);

        if (image === null) {
            return [];
        }

        const key = `home.carousel.sentence.${category.slug}`;
        const own = t(key);

        return [
            {
                id: category.id,
                slug: category.slug,
                name: category.name,
                sentence:
                    own === key ? t('home.carousel.sentence.default') : own,
                image,
            },
        ];
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
