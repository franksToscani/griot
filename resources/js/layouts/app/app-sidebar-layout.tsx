import { router } from '@inertiajs/react';
import { useEffect, useRef } from 'react';
import { AppContent } from '@/components/app-content';
import { AppShell } from '@/components/app-shell';
import { AppSidebar } from '@/components/app-sidebar';
import { AppSidebarHeader } from '@/components/app-sidebar-header';
import { ProviderMenuButton } from '@/components/provider-menu-button';
import { MAIN_CONTENT_ID, SkipLink } from '@/components/skip-link';
import { useSidebar } from '@/components/ui/sidebar';
import { markClosedByNavigation } from '@/lib/menu-navigation';
import type { AppLayoutProps } from '@/types';

// The layout stays between the visits (app.tsx), and the open state of the menu with it: on phones
// the panel would stay open over the new page. Every navigation of Inertia closes it, also Back and
// Forward and the links inside the panel (#244); the items of the menu close it already on click
// (nav-main.tsx). Stops listening when the layout goes.
function CloseMenuOnNavigate() {
    const { openMobile, setOpenMobile } = useSidebar();
    const open = useRef(openMobile);

    useEffect(() => {
        open.current = openMobile;
    }, [openMobile]);

    useEffect(() => {
        return router.on('navigate', () => {
            if (open.current) {
                markClosedByNavigation();
                setOpenMobile(false);
            }
        });
    }, [setOpenMobile]);

    return null;
}

export default function AppSidebarLayout({
    children,
    breadcrumbs = [],
}: AppLayoutProps) {
    return (
        <AppShell>
            {/* Before the sidebar: the first Tab of the page jumps over the menus. */}
            <SkipLink />
            <AppSidebar />
            <AppContent
                id={MAIN_CONTENT_ID}
                tabIndex={-1}
                className="min-w-0 overflow-x-clip outline-none"
            >
                <AppSidebarHeader breadcrumbs={breadcrumbs} />
                {children}
            </AppContent>
            {/* After the content: the last stop of Tab, under the thumb on phones. */}
            <ProviderMenuButton />
            <CloseMenuOnNavigate />
        </AppShell>
    );
}
