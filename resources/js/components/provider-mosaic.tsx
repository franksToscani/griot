import { useRef, useState } from 'react';
import type { MouseEvent } from 'react';
import { PhotoLightbox } from '@/components/photo-lightbox';
import { Button } from '@/components/ui/button';
import { useTranslation } from '@/hooks/use-translation';
import {
    fallbackSrc,
    mosaicTiles,
    photoAlt,
    seeAllPhotos,
    srcSet,
} from '@/lib/gallery';
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
// No title of its own: it comes before the page's h1, so a group with a name instead, as the
// groups of times in the booking form. Each photo shown is a button that opens the zoom on it;
// "Vedi tutte le foto" opens it on the first, when some photos are not in the mosaic.
export function ProviderMosaic({
    photos,
    providerName,
}: {
    photos: ProviderPhotoPublic[];
    providerName: string;
}) {
    const { t } = useTranslation();
    const tiles = mosaicTiles(photos.length);
    const seeAll = seeAllPhotos(photos.length);
    // The open photo in the zoom, or null; and the button that opened it, to give it the focus
    // back on closing.
    const [openIndex, setOpenIndex] = useState<number | null>(null);
    const opener = useRef<HTMLElement | null>(null);

    const open = (event: MouseEvent<HTMLButtonElement>, index: number) => {
        opener.current = event.currentTarget;
        setOpenIndex(index);
    };

    return (
        <div role="group" aria-labelledby={LABEL_ID}>
            <p id={LABEL_ID} className="sr-only">
                {t('providers.show.photos')}
            </p>
            <div className="grid grid-cols-2 gap-2 md:aspect-[2/1] md:grid-cols-4 md:grid-rows-2">
                {tiles.map((tile, index) => {
                    const photo = photos[index];
                    const first = index === 0;

                    // The name: the photo's alternative text, then what the button does. The
                    // tiles are much larger than 44px at every width.
                    return (
                        <button
                            key={photo.id}
                            type="button"
                            aria-haspopup="dialog"
                            onClick={(event) => open(event, index)}
                            className={cn(
                                'overflow-hidden rounded-md outline-none focus-visible:ring-[3px] focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:outline-hidden forced-colors:border',
                                SPAN_CLASSES[tile.span],
                                tile.wideOnly && 'max-md:hidden',
                            )}
                        >
                            {/* The large photo is the first thing on the page: loaded at once and
                                first. The others only when near, without holding the page back. */}
                            <img
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
                                    ? {
                                          loading: 'eager',
                                          fetchPriority: 'high',
                                      }
                                    : { loading: 'lazy', decoding: 'async' })}
                                className="size-full rounded-md bg-muted object-cover forced-colors:border"
                            />
                            <span className="sr-only">
                                {' '}
                                {t('providers.show.open_photo', {
                                    number: index + 1,
                                    total: photos.length,
                                })}
                            </span>
                        </button>
                    );
                })}
            </div>

            {/* Only when some photos are not in the mosaic at that width: below 768 px only with
                4 or 5 photos, at every width with 6 or more. The kit's Button: 44px on phones. */}
            {seeAll !== 'never' && (
                <Button
                    type="button"
                    variant="outline"
                    aria-haspopup="dialog"
                    onClick={(event) => open(event, 0)}
                    className={cn('mt-2', seeAll === 'narrow' && 'md:hidden')}
                >
                    {t('providers.show.see_all', { count: photos.length })}
                </Button>
            )}

            <PhotoLightbox
                photos={photos}
                providerName={providerName}
                index={openIndex}
                onIndexChange={setOpenIndex}
                onClose={() => setOpenIndex(null)}
                opener={opener}
            />
        </div>
    );
}
