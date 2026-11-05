import { Link } from '@inertiajs/react';
import { Badge } from '@/components/ui/badge';
import { useTranslation } from '@/hooks/use-translation';
import { fallbackSrc, srcSet } from '@/lib/gallery';
import { touchTarget } from '@/lib/touch-target';
import { cn } from '@/lib/utils';
import { show } from '@/routes/providers';
import type { FeaturedProvider } from '@/types';

// A card of "Professionisti in evidenza" (issue #144): a link to the provider's page with the
// cover, the name (h3, under the section's h2), the city and the categories. Its own card, not
// the list's ProviderCard: that one has an h2 and the avatar, this one the cover and no avatar.
export function FeaturedProviderCard({
    provider,
}: {
    provider: FeaturedProvider;
}) {
    const { t } = useTranslation();

    return (
        <Link
            href={show(provider.slug)}
            className={cn(
                'flex w-64 flex-col overflow-hidden rounded-xl border bg-card text-card-foreground outline-none hover:bg-accent focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-hidden',
                touchTarget,
            )}
        >
            {/* Decorative: the card says whose work it is. The card is 16rem wide; the space is
                kept while it loads. */}
            {provider.cover && (
                <img
                    src={fallbackSrc(provider.cover.variants)}
                    srcSet={srcSet(provider.cover.variants)}
                    sizes="16rem"
                    width={provider.cover.width}
                    height={provider.cover.height}
                    alt=""
                    loading="lazy"
                    decoding="async"
                    className="aspect-[4/3] w-full bg-muted object-cover"
                />
            )}
            <div className="flex flex-col gap-2 p-4">
                {/* The heading says the name and the city: "Trecce Sahel, a Bologna". The city
                    below is the same words, for the eye: not read twice. */}
                <h3 className="font-medium break-words">
                    {provider.name}
                    <span className="sr-only">
                        {t('home.featured.in_city', { city: provider.city })}
                    </span>
                </h3>
                <p aria-hidden="true" className="text-sm text-muted-foreground">
                    {provider.city}
                </p>
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
            </div>
        </Link>
    );
}
