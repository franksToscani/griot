import { Link, usePage } from '@inertiajs/react';
import {
    CalendarCheck,
    Clock,
    Eye,
    ExternalLink,
    LayoutGrid,
    Scissors,
    UserPen,
} from 'lucide-react';
import AppLogo from '@/components/app-logo';
import { LocaleSwitcher } from '@/components/locale-switcher';
import { NavFooter } from '@/components/nav-footer';
import { NavMain } from '@/components/nav-main';
import { NavUser } from '@/components/nav-user';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { useTranslation } from '@/hooks/use-translation';
import { pendingRequestsText } from '@/lib/pending-requests';
import { dashboard } from '@/routes';
import { edit, preview } from '@/routes/provider';
import { index as availabilityIndex } from '@/routes/provider/availability';
import { index as bookingsIndex } from '@/routes/provider/bookings';
import { index as servicesIndex } from '@/routes/provider/services';
import { show } from '@/routes/providers';
import type { NavGroup, NavItem } from '@/types';

// Ready for future links (help, contacts): NavFooter shows nothing while the list is empty.
const footerNavItems: NavItem[] = [];

// The sections of the provider's area (docs/bookings.md, "Barra dell'area del professionista"):
// each one is a page with its own address, so Back and the automatic update keep working.
export function AppSidebar() {
    const { t } = useTranslation();
    // Missing outside the private area, null for a user without a provider.
    const nav = usePage().props.provider_nav ?? null;
    const pending = nav?.pending_requests ?? 0;

    const groups: NavGroup[] = [
        {
            items: [
                {
                    titleKey: 'nav.overview',
                    href: dashboard(),
                    icon: LayoutGrid,
                },
            ],
        },
        {
            labelKey: 'nav.group.agenda',
            items: [
                {
                    titleKey: 'provider.area.bookings',
                    href: bookingsIndex(),
                    icon: CalendarCheck,
                    badge: {
                        value: pending,
                        text: pendingRequestsText(t, pending),
                    },
                },
            ],
        },
        {
            labelKey: 'nav.group.offer',
            items: [
                {
                    titleKey: 'provider.area.services',
                    href: servicesIndex(),
                    icon: Scissors,
                },
                {
                    titleKey: 'provider.area.availability',
                    href: availabilityIndex(),
                    icon: Clock,
                },
            ],
        },
        {
            labelKey: 'nav.group.showcase',
            items: [
                {
                    titleKey: 'provider.area.edit_profile',
                    href: edit(),
                    icon: UserPen,
                },
                // Always: the owner sees the page as the customers do, published or not.
                {
                    titleKey: 'provider.area.preview',
                    href: preview(),
                    icon: Eye,
                },
                // Only when published: an unpublished page gives a 404 to everyone.
                ...(nav?.public_slug
                    ? [
                          {
                              titleKey: 'provider.area.public_page',
                              href: show(nav.public_slug),
                              icon: ExternalLink,
                          },
                      ]
                    : []),
            ],
        },
    ];

    return (
        <Sidebar collapsible="icon" variant="inset">
            <SidebarHeader>
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton size="lg" asChild>
                            <Link href={dashboard()} prefetch>
                                <AppLogo />
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarHeader>

            <SidebarContent>
                <NavMain groups={groups} />
            </SidebarContent>

            <SidebarFooter>
                <NavFooter items={footerNavItems} className="mt-auto" />
                {/* Hidden when the sidebar is folded to icons: a select does not fit there. */}
                <LocaleSwitcher className="px-2 group-data-[collapsible=icon]:hidden" />
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
