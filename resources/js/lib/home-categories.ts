// The row "Categorie" of the home page (docs/home.md): the small rules, apart from React so that
// they can be tested with Node. One card per category, in the order of the prop (the offered ones
// first): the photo of HOME_PHOTOS, or a coloured tile when the category has none. An offered card
// is a link; the others say "In arrivo".
// Only types are imported: Node runs this file as it is (HomeCategoriesRowTest), without the "@/"
// paths of Vite.
import type { HomePhoto, SlideImage } from '@/lib/home-carousel';
import type { HomeCategory } from '@/types';

// The colours of the tiles of the categories without a photo, in turn: brown, amber, light green,
// white. Only the tokens of resources/css/app.css; never red. The tile holds no text (the name is
// under it, on the page's background), so only its edge matters: CATEGORY_TILE_EDGE.
export const CATEGORY_TONES = [
    'bg-primary',
    'bg-highlight',
    'bg-info',
    'bg-card',
] as const;

export type CategoryTone = (typeof CATEGORY_TONES)[number];

// The edge of every tile, whatever its colour: the amber, the light green and the white are too
// close to the page's background (below 3:1), the border is not (AccessibilityContrastTest). It
// stays in forced colours, where the background of the tile is gone.
export const CATEGORY_TILE_EDGE = 'border border-input';

// The width of a card (w-36, 9rem): the browser takes the 640 px size, even on a screen at 3x.
export const CATEGORY_PHOTO_SIZES = '9rem';

export type CategoryCard = {
    id: number;
    slug: string;
    name: string;
    // The photo, or null: then the tile, with its tone.
    image: SlideImage | null;
    tone: CategoryTone | null;
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

// The cards, in the order of the categories. The tones go in turn over the categories without a
// photo only: the first tile is brown, the second amber, and so on, whatever photos are between.
export function categoryCards(
    categories: HomeCategory[],
    photos: Record<string, HomePhoto> = {},
): CategoryCard[] {
    let tiles = 0;

    return categories.map((category) => {
        const image = categoryImage(category.slug, photos);
        const card = {
            id: category.id,
            slug: category.slug,
            name: category.name,
            offered: category.offered,
        };

        if (image === null) {
            const tone = CATEGORY_TONES[tiles % CATEGORY_TONES.length];
            tiles += 1;

            return { ...card, image: null, tone };
        }

        return { ...card, image, tone: null };
    });
}
