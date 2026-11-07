import { Link } from '@inertiajs/react';
import {
    CATEGORY_PHOTO_SIZES,
    CATEGORY_TILE_EDGE,
} from '@/lib/home-categories';
import type { CategoryCard as Card } from '@/lib/home-categories';
import { touchTarget } from '@/lib/touch-target';
import { cn } from '@/lib/utils';
import { index } from '@/routes/providers';

// A card of the row "Categorie" of the home page (docs/home.md): one link to the list filtered on
// the category. On top a photo or a coloured tile, 6rem high, slightly rounded corners; under it
// the name, on the page's background. Never text on the photo, never a heading: the name is the
// link's text.
export function CategoryCard({ card }: { card: Card }) {
    return (
        <Link
            href={index({ query: { category: card.slug } })}
            className={cn(
                'group flex w-36 flex-col gap-2 rounded-md outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-hidden',
                touchTarget,
            )}
        >
            {card.image !== null ? (
                // Decorative: the name is in the text under it.
                <img
                    src={card.image.src}
                    srcSet={card.image.srcSet}
                    sizes={CATEGORY_PHOTO_SIZES}
                    width={card.image.width}
                    height={card.image.height}
                    alt=""
                    loading="lazy"
                    decoding="async"
                    className="h-24 w-full rounded-md bg-muted object-cover"
                />
            ) : (
                <span
                    aria-hidden="true"
                    className={cn(
                        'block h-24 w-full rounded-md',
                        CATEGORY_TILE_EDGE,
                        card.tone,
                    )}
                />
            )}
            <span className="font-medium break-words group-hover:underline">
                {card.name}
            </span>
        </Link>
    );
}
