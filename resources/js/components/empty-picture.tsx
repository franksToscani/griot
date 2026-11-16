import { Sparkles } from 'lucide-react';
import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';

// The place of a photo that does not exist yet (visual polish, docs/photos.md): a category without
// a photo on the home page, a professional without photos of the work in the list. The same for
// all: sand with a small neutral sign, never a colour in turn (a full brown square next to a photo
// looked like an image that did not load) and never the mark of the provisional logo, which will
// change (docs/brand.md). The sign is decorative, hidden from screen readers: the name is written
// next to it. With children (the professional's avatar) they stay in the middle and the sign goes
// to a corner.
export function EmptyPicture({
    className,
    children,
}: {
    className?: string;
    children?: ReactNode;
}) {
    return (
        <span
            className={cn(
                'relative flex items-center justify-center bg-secondary text-muted-foreground',
                className,
            )}
        >
            {children}
            <Sparkles
                aria-hidden="true"
                className={
                    children ? 'absolute right-3 bottom-3 size-5' : 'size-6'
                }
            />
        </span>
    );
}
