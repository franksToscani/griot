import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';

// The title of a section of the home page (visual polish): an h2 in the font of the titles, and
// under it a short orange bar, the same for every section. The bar is decorative: no text on it,
// no meaning, gone in forced colours where it would say nothing. With "focusable" the help panel
// can put the focus on the title (tabIndex -1, never the Tab key).
export function SectionTitle({
    id,
    focusable = false,
    children,
}: {
    id: string;
    focusable?: boolean;
    children: ReactNode;
}) {
    return (
        <div className="flex flex-col gap-2">
            <h2
                id={id}
                tabIndex={focusable ? -1 : undefined}
                className={cn(
                    'text-2xl font-semibold tracking-tight',
                    focusable && 'outline-none focus-visible:outline-hidden',
                )}
            >
                {children}
            </h2>
            <span
                aria-hidden="true"
                className="block h-1 w-12 rounded-full bg-brand forced-colors:hidden"
            />
        </div>
    );
}
