// The row "Categorie" of the home page (docs/home.md): the small rules, apart from React so that
// they can be tested with Node. One card per category, in the order of the prop (the offered ones
// first): the photo of HOME_PHOTOS, or the same empty tile for every category that has none. An
// offered card is a link; the others say "In arrivo".
// Only types are imported: Node runs this file as it is (HomeCategoriesRowTest), without the "@/"
// paths of Vite.
import type { HomePhoto, SlideImage } from '@/lib/home-carousel';
import type { HomeCategory } from '@/types';

// The tile of a category without a photo (components/empty-picture.tsx, docs/photos.md): the same
// for every category, until the photo exists. The tile holds no text (the name is under it), so
// only its edge matters: the sand is too close to the page's background (below 3:1), the border is
// not (AccessibilityContrastTest). It stays in forced colours, where the background is gone.
export const CATEGORY_TILE_EDGE = 'border border-input';

// The width of a card (w-36, 9rem): the browser takes the 640 px size, even on a screen at 3x.
export const CATEGORY_PHOTO_SIZES = '9rem';

export type CategoryCard = {
    id: number;
    slug: string;
    name: string;
    // The photo, or null: then the empty tile (CATEGORY_TILE).
    image: SlideImage | null;
    // Something to book: a link to the list. Otherwise a card that says "In arrivo".
    offered: boolean;
};

// The photo of a card, or null when the category has none. src is the smallest size (a card is
// narrow, unlike a slide of the carousel); srcset has them all, smallest first.
export function categoryImage(
    slug: string,
    photos: Record<string, HomePhoto>,
): SlideImage | null {
    const photo = Object.hasOwn(photos, slug) ? photos[slug] : undefined;

    if (photo === undefined || photo.variants.length === 0) {
        return null;
    }

    const variants = [...photo.variants].sort((a, b) => a.width - b.width);

    return {
        src: variants[0].url,
        srcSet: variants
            .map((variant) => `${variant.url} ${variant.width}w`)
            .join(', '),
        width: photo.width,
        height: photo.height,
    };
}

// The cards, in the order of the categories: each with its photo, or none (the empty tile).
export function categoryCards(
    categories: HomeCategory[],
    photos: Record<string, HomePhoto> = {},
): CategoryCard[] {
    return categories.map((category) => ({
        id: category.id,
        slug: category.slug,
        name: category.name,
        offered: category.offered,
        image: categoryImage(category.slug, photos),
    }));
}
