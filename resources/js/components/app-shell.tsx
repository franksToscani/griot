import { usePage } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { SidebarProvider } from '@/components/ui/sidebar';

// The private area always has the sidebar (#115: the kit's header layout is gone). Its state,
// open or folded to icons, comes from the cookie the server reads (sidebarOpen).
export function AppShell({ children }: { children: ReactNode }) {
    const isOpen = usePage().props.sidebarOpen;

    return <SidebarProvider defaultOpen={isOpen}>{children}</SidebarProvider>;
}
