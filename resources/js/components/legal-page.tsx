import type { ReactNode } from 'react';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';

// The pieces shared by the legal pages, /privacy and /terms (issue #131, docs/legal.md): the same
// draft notice, the same sections and the same visible placeholders.

// A missing value of the configuration, shown so that nobody can miss it.
export function Placeholder({ children }: { children: ReactNode }) {
    return (
        <span className="rounded bg-highlight px-1 font-medium text-highlight-foreground">
            {children}
        </span>
    );
}

// A section of the page: its h2, under the page's h1.
export function LegalSection({
    title,
    children,
}: {
    title: string;
    children: ReactNode;
}) {
    return (
        <section className="flex flex-col gap-3">
            <h2 className="text-lg font-semibold">{title}</h2>
            {children}
        </section>
    );
}

// "Draft under review", at the top, until a competent person has checked the text.
export function DraftNotice({ title, body }: { title: string; body: string }) {
    return (
        <Alert className="border-highlight-foreground bg-highlight text-highlight-foreground">
            <AlertTitle>{title}</AlertTitle>
            <AlertDescription className="text-current">{body}</AlertDescription>
        </Alert>
    );
}
