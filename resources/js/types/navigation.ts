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
};
