import type { InertiaLinkProps } from '@inertiajs/react';
import type { LucideIcon } from 'lucide-react';

// The titles are translation keys, not texts: the breadcrumbs and the menus are static objects,
// outside the components, so the component that shows them translates them with t().
export type BreadcrumbItem = {
    titleKey: string;
    href: NonNullable<InertiaLinkProps['href']>;
};

export type NavItem = {
    titleKey: string;
    href: NonNullable<InertiaLinkProps['href']>;
    icon?: LucideIcon | null;
    isActive?: boolean;
    // A number next to the label (the requests waiting for an answer), with its text for screen
    // readers, already translated. Hidden when the value is 0.
    badge?: { value: number; text: string };
};

// A group of the sidebar: its label (a key; none for the first one) and its links.
export type NavGroup = {
    labelKey?: string;
    items: NavItem[];
};
