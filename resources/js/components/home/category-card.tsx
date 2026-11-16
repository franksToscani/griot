import { Link } from '@inertiajs/react';
import { EmptyPicture } from '@/components/empty-picture';
import { useTranslation } from '@/hooks/use-translation';
import {
    CATEGORY_PHOTO_SIZES,
    CATEGORY_TILE_EDGE,
} from '@/lib/home-categories';
import type { CategoryCard as Card } from '@/lib/home-categories';
import { CARD_MOTION, CARD_REST } from '@/lib/motion';
import { touchTarget } from '@/lib/touch-target';
import { cn } from '@/lib/utils';
import { index } from '@/routes/providers';

// A card of the row "Categorie" of the home page (docs/home.md). On top a photo or the empty tile
// (EmptyPicture), 6rem high, rounded as the cards (rounded-xl), with the cards' resting shadow;
// under it the name, on the page's background. Never text on the photo, never a heading.
// - Offered (something to book): one link to the list filtered on the category; the name is the
//   link's text. Under the mouse or the focus it rises and its photo grows inside its frame
//   (CARD_MOTION, shared with the featured providers).
// - Not offered yet: no link, no focus, nothing that moves; under the name the written label
//   "In arrivo", never told by a colour only.
export function CategoryCard({ card }: { card: Card }) {
    const { t } = useTranslation();

    if (!card.offered) {
        return (
            <div className="flex w-36 flex-col gap-2">
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
                'group flex w-36 flex-col gap-2 rounded-xl outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-hidden',
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
                    'h-24 w-full rounded-xl',
                    CATEGORY_TILE_EDGE,
                    CARD_REST,
                )}
            />
        );
    }

    return (
        <span className={cn('block rounded-xl', CARD_REST, CARD_MOTION.frame)}>
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
                    'h-24 w-full rounded-xl bg-muted object-cover',
                    CARD_MOTION.photo,
                )}
            />
        </span>
    );
}
