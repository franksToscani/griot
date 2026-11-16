import type * as React from 'react';
import { SidebarInset } from '@/components/ui/sidebar';

// The content next to the sidebar: a <main> (SidebarInset) that gets every prop, so the skip link
// can reach it by its id.
export function AppContent({
    children,
    ...props
}: React.ComponentProps<'main'>) {
    return <SidebarInset {...props}>{children}</SidebarInset>;
}
