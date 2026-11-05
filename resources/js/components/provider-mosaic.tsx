import { useTranslation } from '@/hooks/use-translation';
import { fallbackSrc, mosaicTiles, photoAlt, srcSet } from '@/lib/gallery';
import type { MosaicSpan } from '@/lib/gallery';
import { cn } from '@/lib/utils';
import type { ProviderPhotoPublic } from '@/types';

// Where each photo sits (lib/gallery.ts, mosaicTiles). Below 768 px: two columns, the large photo
// across both at 4:3, the small ones square. From 768 px up: four columns and two rows in a 2:1
// box, the large photo on the left half, the small ones filling the right half without holes.
const SPAN_CLASSES: Record<MosaicSpan, string> = {
    alone: 'col-span-2 aspect-[4/3] md:col-span-4 md:row-span-2 md:aspect-auto',
    large: 'col-span-2 aspect-[4/3] md:row-span-2 md:aspect-auto',
    half: 'aspect-square md:col-span-2 md:row-span-2 md:aspect-auto',
    row: 'aspect-square md:col-span-2 md:aspect-auto',
    quarter: 'aspect-square md:aspect-auto',
};

const LABEL_ID = 'provider-photos-label';

// The mosaic of the provider's work at the top of the public page (issue #143, docs/gallery.md).
// Only to look at in this version: no buttons, no links, nothing that takes the focus, so the
// order of the Tab key is the one of before. No title of its own: it comes before the page's h1,
// so a group with a name instead, as the groups of times in the booking form.
export function ProviderMosaic({
    photos,
    providerName,
}: {
    photos: ProviderPhotoPublic[];
    providerName: string;
}) {
    const { t } = useTranslation();
    const tiles = mosaicTiles(photos.length);

    return (
        <div role="group" aria-labelledby={LABEL_ID}>
            <p id={LABEL_ID} className="sr-only">
                {t('providers.show.photos')}
            </p>
            <div className="grid grid-cols-2 gap-2 md:aspect-[2/1] md:grid-cols-4 md:grid-rows-2">
                {tiles.map((tile, index) => {
                    const photo = photos[index];
                    const first = index === 0;

                    // The large photo is the first thing on the page: loaded at once and first.
                    // The others only when near, without holding the page back.
                    return (
                        <img
                            key={photo.id}
                            src={fallbackSrc(photo.variants)}
                            srcSet={srcSet(photo.variants)}
                            sizes={tile.sizes}
                            width={photo.width}
                            height={photo.height}
                            alt={photoAlt(
                                t,
                                photo.alt_text,
                                index + 1,
                                photos.length,
                                'providers.show.photo_alt',
                                { provider: providerName },
                            )}
                            {...(first
                                ? { loading: 'eager', fetchPriority: 'high' }
                                : { loading: 'lazy', decoding: 'async' })}
                            className={cn(
                                'size-full rounded-md bg-muted object-cover forced-colors:border',
                                SPAN_CLASSES[tile.span],
                                tile.wideOnly && 'max-md:hidden',
                            )}
                        />
                    );
                })}
            </div>
        </div>
    );
}
