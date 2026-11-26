import { Menu as MenuIcon } from 'lucide-react';
import { Breadcrumbs } from '@/components/breadcrumbs';
import { Button } from '@/components/ui/button';
import { useSidebar } from '@/components/ui/sidebar';
import { useTranslation } from '@/hooks/use-translation';
import type { BreadcrumbItem as BreadcrumbItemType } from '@/types';

export function AppSidebarHeader({
    breadcrumbs = [],
}: {
    breadcrumbs?: BreadcrumbItemType[];
}) {
    const { t } = useTranslation();
    const { toggleSidebar } = useSidebar();

    return (
        <header className="flex h-16 shrink-0 items-center gap-2 border-b border-sidebar-border/50 px-6 transition-[width,height] ease-linear group-has-data-[collapsible=icon]/sidebar-wrapper:h-12 md:px-4">
            <div className="flex items-center gap-2">
                {/* "Menu" written, not an icon alone (#244): the same toggleSidebar of the kit as
                    the button at the bottom of phones (provider-menu-button.tsx), so one panel and
                    one state. Its name is the visible text; 44px below 768 px (the kit's Button). */}
                <Button
                    type="button"
                    variant="outline"
                    onClick={toggleSidebar}
                    className="-ml-1"
                >
                    <MenuIcon aria-hidden="true" />
                    {t('nav.menu')}
                </Button>
                <Breadcrumbs breadcrumbs={breadcrumbs} />
            </div>
        </header>
    );
}
