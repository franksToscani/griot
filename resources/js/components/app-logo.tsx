import { usePage } from '@inertiajs/react';

import AppLogoIcon from '@/components/app-logo-icon';

// The name of the site as real text, bold and green, over a thin red line (decorative, no
// meaning). In the provider's sidebar, once collapsed to icons, only the small mark is seen: the
// name stays for screen readers (sr-only, not hidden), so the link keeps its name.
export default function AppLogo() {
    const { name } = usePage().props;

    return (
        <>
            <AppLogoIcon className="hidden size-8 shrink-0 group-data-[collapsible=icon]:block" />
            <span className="border-b-2 border-decor pb-0.5 text-xl leading-none font-bold tracking-tight text-primary group-data-[collapsible=icon]:sr-only">
                {name}
            </span>
        </>
    );
}
