import { Menu as MenuIcon } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { useSidebar } from '@/components/ui/sidebar';
import { useTranslation } from '@/hooks/use-translation';

// The menu of the provider's area at the bottom right, under the thumb, on phones only (below
// 768 px, where the sidebar is a panel that opens). The third element that stays in view
// (docs/accessibility.md, « Il tasto Menu in basso »): it opens the same panel as "Menu" at the top
// of the page (toggleSidebar of the kit: one panel, one state). Placed as the home's buttons, out of
// the safe areas of the phones; under the panel and its dark layer (z-40, the kit's Sheet is z-50);
// no movement. app.css leaves room at the end of the page for it and hides it while a field is
// being written in.
export function ProviderMenuButton() {
    const { t } = useTranslation();
    const { toggleSidebar } = useSidebar();

    return (
        <div
            data-provider-menu=""
            className="max-md:fixed max-md:right-[max(1rem,env(safe-area-inset-right))] max-md:bottom-[max(1rem,env(safe-area-inset-bottom))] max-md:z-40 md:hidden"
        >
            <Button
                type="button"
                onClick={toggleSidebar}
                className="h-12 gap-2 rounded-full px-5 shadow-md forced-colors:border"
            >
                <MenuIcon aria-hidden="true" className="size-5" />
                {t('nav.menu')}
            </Button>
        </div>
    );
}
