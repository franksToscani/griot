// The gallery of the provider's work (issue #143, docs/gallery.md): the small rules of the card in
// the edit page and of the mosaic on the public page, apart from React so that they can be tested
// with Node.
import type { TranslateFn } from '@/hooks/use-translation';
import type { PhotoVariant } from '@/types';

// "empty": an invitation, no list. "open": the list and the upload form. "full": the list and a
// message instead of the form.
export type GalleryState = 'empty' | 'open' | 'full';

export function galleryState(count: number, max: number): GalleryState {
    if (count >= max) {
        return 'full';
    }

    return count === 0 ? 'empty' : 'open';
}

// The alternative text of a photo: the provider's own, in the provider's language, or a translated
// one when there is none. By default "Foto 2 di 6", as in the edit page's card; the public page
// passes its own key and the provider's name (the number is the photo's place, from 1).
export function photoAlt(
    t: TranslateFn,
    altText: string | null,
    number: number,
    total: number,
    fallbackKey: string = 'provider.gallery.fallback_alt',
    replacements: Record<string, string | number> = {},
): string {
    const own = altText?.trim() ?? '';

    return own !== ''
        ? own
        : t(fallbackKey, { ...replacements, number, total });
}

// How many photos the mosaic shows: from 768 px up the large one and up to 4 small ones, below it
// the large one and up to 2. The others are reached in the zoom, through "Vedi tutte le foto".
export const MOSAIC_WIDE = 5;
export const MOSAIC_NARROW = 3;

export type MosaicLayout = { wide: number; narrow: number };

export function mosaicLayout(count: number): MosaicLayout {
    const photos = Math.max(0, Math.floor(count));

    return {
        wide: Math.min(photos, MOSAIC_WIDE),
        narrow: Math.min(photos, MOSAIC_NARROW),
    };
}

// The place of a photo in the mosaic, from 768 px up: "alone" takes the whole mosaic, "large" the
// left half; the small ones fill the right half without holes: one takes it all ("half"), two
// take a row each ("row"), with three the first takes a row, with four each takes a quarter
// ("quarter"). Below 768 px the large one is full width and the small ones half width.
export type MosaicSpan = 'alone' | 'large' | 'half' | 'row' | 'quarter';

export type MosaicTile = {
    span: MosaicSpan;
    // The width the photo takes on the screen, for the browser's choice in srcset.
    sizes: string;
    // Shown from 768 px up only: below, the large one and two small ones.
    wideOnly: boolean;
};

export function mosaicTiles(count: number): MosaicTile[] {
    const { wide, narrow } = mosaicLayout(count);

    if (wide === 0) {
        return [];
    }

    const tiles: MosaicTile[] = [
        wide === 1
            ? { span: 'alone', sizes: '100vw', wideOnly: false }
            : {
                  span: 'large',
                  sizes: '(min-width: 768px) 50vw, 100vw',
                  wideOnly: false,
              },
    ];
    const small = wide - 1;

    for (let index = 0; index < small; index++) {
        const span: MosaicSpan =
            small === 1
                ? 'half'
                : small === 2 || (small === 3 && index === 0)
                  ? 'row'
                  : 'quarter';

        tiles.push({
            span,
            sizes:
                span === 'quarter' ? '(min-width: 768px) 25vw, 50vw' : '50vw',
            wideOnly: index + 1 >= narrow,
        });
    }

    return tiles;
}

// When "Vedi tutte le foto" is shown: only when some photos are not in the mosaic at that width.
// "narrow": below 768 px only (4 or 5 photos), "always": at every width (6 or more), "never":
// every photo is already in the mosaic.
export type SeeAllPhotos = 'never' | 'narrow' | 'always';

export function seeAllPhotos(count: number): SeeAllPhotos {
    const photos = Math.max(0, Math.floor(count));
    const { wide, narrow } = mosaicLayout(photos);

    if (photos > wide) {
        return 'always';
    }

    return photos > narrow ? 'narrow' : 'never';
}

// The next and the previous photo in the zoom, going round: after the last comes the first and
// before the first the last, so no button is ever disabled under the focus.
export function nextIndex(index: number, total: number): number {
    return total > 0 ? (index + 1) % total : 0;
}

export function previousIndex(index: number, total: number): number {
    return total > 0 ? (index - 1 + total) % total : 0;
}

// "Foto 2 di 6": the title of the zoom, and what is announced when the photo changes.
export function photoPosition(
    t: TranslateFn,
    index: number,
    total: number,
): string {
    return t('providers.show.lightbox.title', { number: index + 1, total });
}

// The srcset of a photo: every size that exists, smallest first, as "address width". A space or a
// comma would split an address in two: they are written as %20 and %2C.
export function srcSet(variants: PhotoVariant[]): string {
    return [...variants]
        .sort((a, b) => a.width - b.width)
        .map(
            (variant) =>
                `${variant.url.replace(/[\s,]/g, (char) => encodeURIComponent(char))} ${variant.width}w`,
        )
        .join(', ');
}

// The src for a browser that does not read srcset: the largest size up to 800 px wide, or the
// smallest when every one is wider.
export function fallbackSrc(variants: PhotoVariant[]): string {
    const sorted = [...variants].sort((a, b) => a.width - b.width);
    const upTo800 = sorted.filter((variant) => variant.width <= 800);

    return (upTo800.at(-1) ?? sorted[0])?.url ?? '';
}
