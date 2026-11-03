import { AppContent } from '@/components/app-content';
import { AppShell } from '@/components/app-shell';
import { AppSidebar } from '@/components/app-sidebar';
import { AppSidebarHeader } from '@/components/app-sidebar-header';
import { MAIN_CONTENT_ID, SkipLink } from '@/components/skip-link';
import type { AppLayoutProps } from '@/types';

export default function AppSidebarLayout({
    children,
    breadcrumbs = [],
}: AppLayoutProps) {
    return (
        <AppShell variant="sidebar">
            {/* Before the sidebar: the first Tab of the page jumps over the menus. */}
            <SkipLink />
            <AppSidebar />
            <AppContent
                id={MAIN_CONTENT_ID}
                tabIndex={-1}
                variant="sidebar"
                className="min-w-0 overflow-x-clip outline-none"
            >
                <AppSidebarHeader breadcrumbs={breadcrumbs} />
                {children}
            </AppContent>
        </AppShell>
    );
}
