import { Link } from '@inertiajs/react';
import {
    SidebarGroup,
    SidebarGroupLabel,
    SidebarMenu,
    SidebarMenuBadge,
    SidebarMenuButton,
    SidebarMenuItem,
    useSidebar,
} from '@/components/ui/sidebar';
import { useCurrentUrl } from '@/hooks/use-current-url';
import { useTranslation } from '@/hooks/use-translation';
import { markClosedByNavigation } from '@/lib/menu-navigation';
import { toUrl } from '@/lib/utils';
import type { NavGroup } from '@/types';

// The sections of the provider's area, in groups (docs/accessibility.md, "Barra laterale"). A link
// is active on its page and on the pages under it, by whole segments of the address: "Prenotazioni"
// on /provider/bookings/12, "Servizi" on /provider/services/3/edit, never on /provider/bookingsx.
// The active link has aria-current="page"; the kit marks it with data-active for the colours.
export function NavMain({ groups }: { groups: NavGroup[] }) {
    const { currentUrl } = useCurrentUrl();
    const { t } = useTranslation();
    const { isMobile, setOpenMobile } = useSidebar();

    // On phones the menu is a panel over the page: a chosen page closes it at once, also the page
    // already shown, and the focus goes to the content (lib/menu-navigation.ts, #244).
    const closeMenu = () => {
        if (isMobile) {
            markClosedByNavigation();
            setOpenMobile(false);
        }
    };

    const isActive = (href: NavGroup['items'][number]['href']): boolean => {
        const path = new URL(toUrl(href), window.location.origin).pathname;

        return currentUrl === path || currentUrl.startsWith(`${path}/`);
    };

    return (
        <>
            {groups.map((group, index) => (
                <SidebarGroup
                    key={group.labelKey ?? `group-${index}`}
                    className="px-2 py-0"
                >
                    {group.labelKey !== undefined && (
                        <SidebarGroupLabel>
                            {t(group.labelKey)}
                        </SidebarGroupLabel>
                    )}
                    <SidebarMenu>
                        {group.items.map((item) => {
                            const active = isActive(item.href);
                            const badge =
                                item.badge !== undefined && item.badge.value > 0
                                    ? item.badge
                                    : null;

                            return (
                                <SidebarMenuItem key={item.titleKey}>
                                    <SidebarMenuButton
                                        asChild
                                        isActive={active}
                                        tooltip={{ children: t(item.titleKey) }}
                                        className="relative"
                                    >
                                        <Link
                                            href={item.href}
                                            prefetch
                                            onClick={closeMenu}
                                            aria-current={
                                                active ? 'page' : undefined
                                            }
                                        >
                                            {item.icon && <item.icon />}
                                            <span>{t(item.titleKey)}</span>
                                            {badge !== null && (
                                                <>
                                                    {/* The number for screen readers, in the name of the link. */}
                                                    <span className="sr-only">
                                                        {`, ${badge.text}`}
                                                    </span>
                                                    {/* Folded to icons: a dot on the icon, the label in the tooltip. */}
                                                    <span
                                                        aria-hidden="true"
                                                        className="absolute top-1 right-1 hidden size-2 rounded-full bg-primary group-data-[collapsible=icon]:block"
                                                    />
                                                </>
                                            )}
                                        </Link>
                                    </SidebarMenuButton>
                                    {badge !== null && (
                                        // Seen only: the text is in the link. Centred in the 44 px
                                        // of the phones (the kit puts it 6 px from the top).
                                        <SidebarMenuBadge
                                            aria-hidden="true"
                                            className="max-md:top-3!"
                                        >
                                            {badge.value}
                                        </SidebarMenuBadge>
                                    )}
                                </SidebarMenuItem>
                            );
                        })}
                    </SidebarMenu>
                </SidebarGroup>
            ))}
        </>
    );
}
