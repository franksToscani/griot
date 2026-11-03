import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { useInitials } from '@/hooks/use-initials';
import { useTranslation } from '@/hooks/use-translation';
import { cn } from '@/lib/utils';

type Props = {
    name: string;
    avatarUrl: string | null;
    className?: string;
    // True where the provider's name is written next to the photo (card, page, panel): the photo
    // adds nothing to read, so the name is not read twice. False where the photo stands alone.
    decorative?: boolean;
};

// The provider's photo, or its initials when there is none. The initials are never read: as
// letters ("S A") they say nothing that the name does not.
export function ProviderAvatar({
    name,
    avatarUrl,
    className,
    decorative = true,
}: Props) {
    const getInitials = useInitials();
    const { t } = useTranslation();

    return (
        <Avatar className={cn('size-12', className)}>
            {avatarUrl && (
                <AvatarImage
                    src={avatarUrl}
                    alt={decorative ? '' : t('provider.avatar.alt', { name })}
                />
            )}
            <AvatarFallback aria-hidden="true">
                {getInitials(name)}
            </AvatarFallback>
        </Avatar>
    );
}
