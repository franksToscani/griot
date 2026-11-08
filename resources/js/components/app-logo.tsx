import { usePage } from '@inertiajs/react';

import AppLogoIcon from '@/components/app-logo-icon';
import { WORDMARK } from '@/lib/brand';

// The look of both images of the word GRIOT: 28 px high on phones, 36 px from 768 px, their
// width from their own ratio. Hidden in forced colours, where an image keeps its colours and
// could vanish on the system's background: the name is written instead.
const IMAGE = 'h-7 w-auto md:h-9 forced-colors:hidden';

// The name of the site (docs/brand.md): the provisional logo, the word GRIOT with its pin, brown
// letters in the light theme and cream in the dark one (two images, as the site has its own
// switch of the theme, the class .dark). Their alternative text is the name of the app, so the
// link that holds them is named by it. In forced colours the name as text, in the colour of the
// buttons. In the provider's sidebar, once collapsed to icons, only the small mark is seen: the
// name stays for screen readers (sr-only, not hidden), so the link keeps its name.
export default function AppLogo() {
    const { name } = usePage().props;

    return (
        <>
            <AppLogoIcon className="hidden size-8 shrink-0 group-data-[collapsible=icon]:flex" />
            <span className="contents group-data-[collapsible=icon]:hidden">
                <img
                    src={WORDMARK.light.src}
                    srcSet={WORDMARK.light.srcSet}
                    width={WORDMARK.light.width}
                    height={WORDMARK.light.height}
                    alt={name}
                    className={`${IMAGE} dark:hidden`}
                />
                <img
                    src={WORDMARK.dark.src}
                    srcSet={WORDMARK.dark.srcSet}
                    width={WORDMARK.dark.width}
                    height={WORDMARK.dark.height}
                    alt={name}
                    className={`hidden dark:not-forced-colors:block ${IMAGE}`}
                />
            </span>
            <span className="hidden text-xl leading-none font-bold tracking-tight text-primary group-data-[collapsible=icon]:sr-only group-data-[collapsible=icon]:inline forced-colors:inline">
                {name}
            </span>
        </>
    );
}
