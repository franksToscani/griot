import { Link } from '@inertiajs/react';
import { ProviderAvatar } from '@/components/provider-avatar';
import { Badge } from '@/components/ui/badge';
import { useTranslation } from '@/hooks/use-translation';
import { formatPrice } from '@/lib/format';
import { fallbackSrc, srcSet } from '@/lib/gallery';
import { CARD_MOTION } from '@/lib/motion';
import { touchTarget } from '@/lib/touch-target';
import { cn } from '@/lib/utils';
import { show } from '@/routes/providers';
import type { ProviderListItem } from '@/types';

// The width of a card in the grid of ResultsLayout without an aside: the whole column on phones,
// half from 640 px, a third from 1024 px, at most a third of the site's container (72rem less
// its 2rem of padding and the two 1rem gaps: about 23rem).
const COVER_SIZES =
    '(min-width: 1152px) 23rem, (min-width: 1024px) 33vw, (min-width: 640px) 50vw, 100vw';

// A card of the public list (docs/search.md): one link to the provider's page. On top the first
// photo of the work, 4:3, decorative (the card says the name); without photos the avatar, in a
// box of the same size, so every card has the same shape. Under it the name (h2: the cards are
// under the page's h1), the city, the categories and "N servizi · da X €". It rises under the
// mouse or the focus as the cards of the home page (CARD_MOTION, only without "reduce motion").
export function ProviderCard({ provider }: { provider: ProviderListItem }) {
    const { t, locale } = useTranslation();
    const services =
        provider.services_count === 1
            ? t('providers.card.services_one')
            : t('providers.card.services', { count: provider.services_count });

    return (
        <Link
            href={show(provider.slug)}
            className={cn(
                'flex w-full flex-col overflow-hidden rounded-xl border bg-card text-card-foreground outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-hidden',
                touchTarget,
                CARD_MOTION.card,
            )}
        >
            {provider.cover !== null ? (
                <div className={CARD_MOTION.frame}>
                    <img
                        src={fallbackSrc(provider.cover.variants)}
                        srcSet={srcSet(provider.cover.variants)}
                        sizes={COVER_SIZES}
                        width={provider.cover.width}
                        height={provider.cover.height}
                        alt=""
                        loading="lazy"
                        decoding="async"
                        className={cn(
                            'aspect-[4/3] w-full bg-muted object-cover',
                            CARD_MOTION.photo,
                        )}
                    />
                </div>
            ) : (
                <div className="flex aspect-[4/3] w-full items-center justify-center bg-muted">
                    <ProviderAvatar
                        name={provider.name}
                        avatarUrl={provider.avatar_url}
                        className="size-20"
                    />
                </div>
            )}
            <div className="flex min-w-0 flex-col gap-2 p-4">
                <h2 className="truncate font-medium">{provider.name}</h2>
                <p className="text-sm text-muted-foreground">{provider.city}</p>
                {provider.categories.length > 0 && (
                    <ul className="flex flex-wrap gap-2">
                        {provider.categories.map((category) => (
                            <li key={category.id}>
                                <Badge variant="secondary">
                                    {category.name}
                                </Badge>
                            </li>
                        ))}
                    </ul>
                )}
                <p className="text-sm">
                    {t('providers.card.summary', {
                        services,
                        price: t('providers.card.from_price', {
                            price: formatPrice(locale, provider.min_price),
                        }),
                    })}
                </p>
            </div>
        </Link>
    );
}
