import { Link, usePage } from '@inertiajs/react';
import type { ReactNode } from 'react';
import AppLogo from '@/components/app-logo';
import { LocaleSwitcher } from '@/components/locale-switcher';
import { NewTabHint } from '@/components/new-tab-hint';
import { MAIN_CONTENT_ID, SkipLink } from '@/components/skip-link';
import { useTranslation } from '@/hooks/use-translation';
import { touchTarget } from '@/lib/touch-target';
import { cn } from '@/lib/utils';
import { whatsappLink } from '@/lib/whatsapp';
import { dashboard, home, login, privacy } from '@/routes';

// The name of the group of the site's contacts in the footer.
const FOOTER_CONTACT_ID = 'footer-contact-label';

// Layout for the public pages that customers see without logging in.
export default function PublicLayout({ children }: { children: ReactNode }) {
    const { auth, contact } = usePage().props;
    const { t } = useTranslation();

    return (
        <div className="flex min-h-svh flex-col bg-background">
            <SkipLink />
            <header className="border-b-2 border-decor">
                {/* The thin red line under the header (border-decor): decorative, it means nothing. */}
                <div className="mx-auto flex w-full max-w-6xl items-center justify-between gap-4 px-4 py-3">
                    {/* Never squeezed: on a narrow phone the language and "log in" make room
                        ("Se connecter" goes on two lines), not the name of the site. */}
                    <Link
                        href={home()}
                        className={cn(
                            'flex shrink-0 items-center',
                            touchTarget,
                        )}
                    >
                        <AppLogo />
                    </Link>
                    <div className="flex items-center gap-4">
                        <LocaleSwitcher />
                        <nav className="text-sm">
                            {auth.user ? (
                                <Link
                                    href={dashboard()}
                                    className={touchTarget}
                                >
                                    {t('nav.dashboard')}
                                </Link>
                            ) : (
                                <Link href={login()} className={touchTarget}>
                                    {t('nav.login')}
                                </Link>
                            )}
                        </nav>
                    </div>
                </div>
            </header>
            <main
                id={MAIN_CONTENT_ID}
                tabIndex={-1}
                className="mx-auto w-full max-w-6xl flex-1 px-4 py-6 outline-none"
            >
                {children}
            </main>
            {/* No heading: the footer is on every public page, and must not change their order of
                headings. The links wrap on a narrow phone. */}
            <footer className="border-t">
                <div className="mx-auto flex w-full max-w-6xl flex-wrap items-center gap-x-6 gap-y-2 px-4 py-4 text-sm text-muted-foreground">
                    <Link
                        href={privacy()}
                        className={cn(
                            'underline underline-offset-4',
                            touchTarget,
                        )}
                    >
                        {t('privacy.link')}
                    </Link>
                    {/* The site's contacts, only those configured (config/contact.php): with none,
                        no group at all. A group with a name, read before its links. */}
                    {(contact.email !== null ||
                        contact.whatsapp_url !== null) && (
                        <div
                            role="group"
                            aria-labelledby={FOOTER_CONTACT_ID}
                            className="flex flex-wrap items-center gap-x-4 gap-y-2"
                        >
                            <span id={FOOTER_CONTACT_ID} className="sr-only">
                                {t('footer.contact.label')}
                            </span>
                            {contact.email !== null && (
                                <a
                                    href={`mailto:${contact.email}`}
                                    className={cn(
                                        'underline underline-offset-4',
                                        touchTarget,
                                    )}
                                >
                                    {t('footer.contact.email')}
                                </a>
                            )}
                            {contact.whatsapp_url !== null && (
                                <a
                                    href={whatsappLink(
                                        contact.whatsapp_url,
                                        t('footer.contact.whatsapp_message'),
                                    )}
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    className={cn(
                                        'underline underline-offset-4',
                                        touchTarget,
                                    )}
                                >
                                    {t('footer.contact.whatsapp')}
                                    <NewTabHint />
                                </a>
                            )}
                        </div>
                    )}
                </div>
            </footer>
        </div>
    );
}
