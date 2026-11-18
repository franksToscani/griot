import { Link } from '@inertiajs/react';
import { EmptyPicture } from '@/components/empty-picture';
import { useTranslation } from '@/hooks/use-translation';
import {
    CATEGORY_CARD,
    CATEGORY_PHOTO_SIZES,
    CATEGORY_TILE_EDGE,
} from '@/lib/home-categories';
import type { CategoryCard as Card } from '@/lib/home-categories';
import { CARD_MOTION, CARD_REST } from '@/lib/motion';
import { touchTarget } from '@/lib/touch-target';
import { cn } from '@/lib/utils';
import { index } from '@/routes/providers';

// A card of the row "Categorie" of the home page (docs/home.md). On top a photo or the empty tile
// (EmptyPicture), 7rem high, with the cards' resting shadow; under it the name, on the page's
// background. Never text on the photo, never a heading. 11rem wide (CATEGORY_CARD).
// - Offered (something to book): one link to the list filtered on the category; the name is the
//   link's text. Under the mouse or the focus it rises and its photo grows inside its frame
//   (CARD_MOTION, shared with the featured providers).
// - Not offered yet: no link, no focus, nothing that moves; under the name the written label
//   "In arrivo", never told by a colour only.
export function CategoryCard({ card }: { card: Card }) {
    const { t } = useTranslation();

    if (!card.offered) {
        return (
            <div className={cn('flex flex-col gap-2', CATEGORY_CARD.width)}>
                <CardImage card={card} />
                <span className="font-medium break-words">{card.name}</span>
                <span className="w-fit rounded-full border border-input px-2 py-0.5 text-xs font-medium text-muted-foreground">
                    {t('home.categories.coming_soon')}
                </span>
            </div>
        );
    }

    return (
        <Link
            href={index({ query: { category: card.slug } })}
            className={cn(
                'group flex flex-col gap-2 outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-hidden',
                CATEGORY_CARD.width,
                CATEGORY_CARD.corners,
                touchTarget,
                CARD_MOTION.card,
            )}
        >
            <CardImage card={card} />
            <span className="font-medium break-words group-hover:underline">
                {card.name}
            </span>
        </Link>
    );
}

// The photo, decorative (the name is in the text under it), or the empty tile, the same for every
// category. The frame keeps the photo's corners and cuts it when it grows (only on an offered card,
// inside its group).
function CardImage({ card }: { card: Card }) {
    if (card.image === null) {
        return (
            <EmptyPicture
                className={cn(
                    'w-full',
                    CATEGORY_CARD.image,
                    CATEGORY_CARD.corners,
                    CATEGORY_TILE_EDGE,
                    CARD_REST,
                )}
            />
        );
    }

    return (
        <span
            className={cn(
                'block',
                CATEGORY_CARD.corners,
                CARD_REST,
                CARD_MOTION.frame,
            )}
        >
            <img
                src={card.image.src}
                srcSet={card.image.srcSet}
                sizes={CATEGORY_PHOTO_SIZES}
                width={card.image.width}
                height={card.image.height}
                alt=""
                loading="lazy"
                decoding="async"
                className={cn(
                    'w-full bg-muted object-cover',
                    CATEGORY_CARD.image,
                    CATEGORY_CARD.corners,
                    CARD_MOTION.photo,
                )}
            />
        </span>
    );
}
