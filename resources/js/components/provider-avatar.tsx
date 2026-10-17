import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { useInitials } from '@/hooks/use-initials';
import { cn } from '@/lib/utils';

type Props = {
    name: string;
    avatarUrl: string | null;
    className?: string;
};

// The provider's photo, or its initials when there is none.
export function ProviderAvatar({ name, avatarUrl, className }: Props) {
    const getInitials = useInitials();

    return (
        <Avatar className={cn('size-12', className)}>
            {avatarUrl && <AvatarImage src={avatarUrl} alt={name} />}
            <AvatarFallback>{getInitials(name)}</AvatarFallback>
        </Avatar>
    );
}
