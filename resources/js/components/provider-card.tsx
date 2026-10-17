import { Link } from '@inertiajs/react';
import { ProviderAvatar } from '@/components/provider-avatar';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent } from '@/components/ui/card';
import { show } from '@/routes/providers';
import type { ProviderListItem } from '@/types';

export function ProviderCard({ provider }: { provider: ProviderListItem }) {
    return (
        <Link
            href={show(provider.slug)}
            className="block rounded-xl outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50"
        >
            <Card className="py-4 transition-colors hover:bg-accent/50">
                <CardContent className="flex flex-col gap-3 px-4">
                    <div className="flex items-center gap-3">
                        <ProviderAvatar
                            name={provider.name}
                            avatarUrl={provider.avatar_url}
                        />
                        <div className="min-w-0">
                            <h2 className="truncate font-medium">
                                {provider.name}
                            </h2>
                            <p className="text-sm text-muted-foreground">
                                {provider.city}
                            </p>
                        </div>
                    </div>
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
                </CardContent>
            </Card>
        </Link>
    );
}
