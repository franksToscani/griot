import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent } from '@/components/ui/card';
import { useInitials } from '@/hooks/use-initials';
import type { ProviderListItem } from '@/types';

export function ProviderCard({ provider }: { provider: ProviderListItem }) {
    const getInitials = useInitials();

    return (
        <Card className="py-4">
            <CardContent className="flex flex-col gap-3 px-4">
                <div className="flex items-center gap-3">
                    <Avatar className="size-12">
                        {provider.avatar_url && (
                            <AvatarImage
                                src={provider.avatar_url}
                                alt={provider.name}
                            />
                        )}
                        <AvatarFallback>
                            {getInitials(provider.name)}
                        </AvatarFallback>
                    </Avatar>
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
    );
}
