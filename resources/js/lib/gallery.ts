// The gallery card of the provider's edit page (issue #143, docs/gallery.md): the small rules the
// card follows, apart from React so that they can be tested with Node.
import type { TranslateFn } from '@/hooks/use-translation';

// "empty": an invitation, no list. "open": the list and the upload form. "full": the list and a
// message instead of the form.
export type GalleryState = 'empty' | 'open' | 'full';

export function galleryState(count: number, max: number): GalleryState {
    if (count >= max) {
        return 'full';
    }

    return count === 0 ? 'empty' : 'open';
}

// The alternative text of a thumbnail: the provider's own, or "Foto 2 di 6" when there is none
// (the number is the photo's place in the list, from 1).
export function photoAlt(
    t: TranslateFn,
    altText: string | null,
    number: number,
    total: number,
): string {
    const own = altText?.trim() ?? '';

    return own !== ''
        ? own
        : t('provider.gallery.fallback_alt', { number, total });
}
