import { Link, usePage } from '@inertiajs/react';
import { ArrowUp } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { HOW_IT_WORKS_TITLE_ID } from '@/components/home/how-it-works';
import { MAIN_CONTENT_ID } from '@/components/skip-link';
import { NewTabHint } from '@/components/new-tab-hint';
import { Button } from '@/components/ui/button';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
    SheetTrigger,
} from '@/components/ui/sheet';
import { useTranslation } from '@/hooks/use-translation';
import { whatsappLink } from '@/lib/whatsapp';
import { index } from '@/routes/providers';

// The round buttons of the column at the bottom right: 3rem, more than the 44px of a phone, a
// shadow to stand out from the page, the kit's visible focus ring.
const ROUND = 'size-12 rounded-full shadow-md';

// The two buttons that stay at the bottom right of the home page, and only there (docs/home.md):
// "torna in cima" above, the help "?" below. They stay in place while the page scrolls, so
// docs/accessibility.md ("Elementi che restano fermi") explains how they never hide the focus:
// app.css keeps room for them at the bottom of the page and when the focus scrolls into view, and
// their position follows the safe area of phones.
export function HomeFloatingActions() {
    const { t } = useTranslation();
    const sentinel = useRef<HTMLDivElement>(null);
    // Visible as React draws it; hidden only by the observer after the mount, so without it
    // (an old browser) the arrow is simply always there.
    const [atTop, setAtTop] = useState(false);

    useEffect(() => {
        const element = sentinel.current;

        if (
            element === null ||
            typeof window.IntersectionObserver !== 'function'
        ) {
            return;
        }

        // The sentinel covers the first screen: while it is in view the page is near its top.
        const observer = new IntersectionObserver((entries) => {
            for (const entry of entries) {
                setAtTop(entry.isIntersecting);
            }
        });
        observer.observe(element);

        return () => {
            observer.disconnect();
        };
    }, []);

    // Straight to the top, without a smooth scroll, and the focus at the start of the page.
    const backToTop = () => {
        window.scrollTo(0, 0);
        document
            .getElementById(MAIN_CONTENT_ID)
            ?.focus({ preventScroll: true });
    };

    return (
        <>
            {/* As high as the first screen, at the top of the page: nothing to see, nothing to press. */}
            <div
                ref={sentinel}
                aria-hidden="true"
                className="pointer-events-none absolute top-0 left-0 h-svh w-px"
            />
            <div
                data-floating-actions=""
                className="fixed right-[max(1rem,env(safe-area-inset-right))] bottom-[max(1rem,env(safe-area-inset-bottom))] z-40 flex flex-col items-end gap-3"
            >
                {!atTop && (
                    <Button
                        type="button"
                        variant="outline"
                        size="icon"
                        className={ROUND}
                        onClick={backToTop}
                    >
                        <ArrowUp aria-hidden="true" className="size-5" />
                        <span className="sr-only">{t('home.back_to_top')}</span>
                    </Button>
                )}
                <HelpPanel />
            </div>
        </>
    );
}

// The help panel (the kit's Sheet: the focus stays inside, Esc and a click outside close it, the
// focus goes back to "?"). The site's contacts, each only when it is configured (the shared prop
// "contact", as the footer), and two links: "Come funziona" on this page and the list.
function HelpPanel() {
    const { t } = useTranslation();
    const { contact } = usePage().props;
    const [open, setOpen] = useState(false);
    // Set by "Come funziona": when the panel closes, the focus goes to its title instead of "?".
    const goToSteps = useRef(false);

    return (
        <Sheet open={open} onOpenChange={setOpen}>
            <SheetTrigger asChild>
                <Button type="button" size="icon" className={ROUND}>
                    <span aria-hidden="true" className="text-xl font-semibold">
                        ?
                    </span>
                    <span className="sr-only">{t('home.help.open')}</span>
                </Button>
            </SheetTrigger>
            <SheetContent
                side="right"
                className="overflow-y-auto"
                onCloseAutoFocus={(event) => {
                    if (!goToSteps.current) {
                        return;
                    }

                    goToSteps.current = false;
                    event.preventDefault();
                    // The focus scrolls the title into view: no smooth scroll.
                    document.getElementById(HOW_IT_WORKS_TITLE_ID)?.focus();
                }}
            >
                <SheetHeader>
                    <SheetTitle>{t('home.help.title')}</SheetTitle>
                    <SheetDescription>{t('home.help.text')}</SheetDescription>
                </SheetHeader>
                <div className="flex flex-col gap-3 px-4 pb-4">
                    {contact.whatsapp_url !== null && (
                        <Button asChild>
                            <a
                                href={whatsappLink(
                                    contact.whatsapp_url,
                                    t('home.help.whatsapp_message'),
                                )}
                                target="_blank"
                                rel="noopener noreferrer"
                            >
                                {t('home.help.whatsapp')}
                                <NewTabHint />
                            </a>
                        </Button>
                    )}
                    {contact.phone_url !== null && (
                        <Button variant="outline" asChild>
                            <a href={contact.phone_url}>
                                {t('home.help.call')}
                            </a>
                        </Button>
                    )}
                    {contact.email !== null && (
                        <Button variant="outline" asChild>
                            <a href={`mailto:${contact.email}`}>
                                {t('home.help.email')}
                            </a>
                        </Button>
                    )}
                    <Button
                        type="button"
                        variant="ghost"
                        onClick={() => {
                            goToSteps.current = true;
                            setOpen(false);
                        }}
                    >
                        {t('home.steps.title')}
                    </Button>
                    <Button variant="ghost" asChild>
                        <Link href={index()}>{t('nav.find_provider')}</Link>
                    </Button>
                </div>
            </SheetContent>
        </Sheet>
    );
}
