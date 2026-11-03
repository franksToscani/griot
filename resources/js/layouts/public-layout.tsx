import { Link, usePage } from '@inertiajs/react';
import type { ReactNode } from 'react';
import AppLogo from '@/components/app-logo';
import { LocaleSwitcher } from '@/components/locale-switcher';
import { MAIN_CONTENT_ID, SkipLink } from '@/components/skip-link';
import { useTranslation } from '@/hooks/use-translation';
import { touchTarget } from '@/lib/touch-target';
import { cn } from '@/lib/utils';
import { dashboard, home, login, privacy } from '@/routes';

// Layout for the public pages that customers see without logging in.
export default function PublicLayout({ children }: { children: ReactNode }) {
    const { auth } = usePage().props;
    const { t } = useTranslation();

    return (
        <div className="flex min-h-svh flex-col bg-background">
            <SkipLink />
            <header className="border-b">
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
            <footer className="border-t">
                <div className="mx-auto w-full max-w-6xl px-4 py-4 text-sm text-muted-foreground">
                    <Link
                        href={privacy()}
                        className={cn(
                            'underline underline-offset-4',
                            touchTarget,
                        )}
                    >
                        {t('privacy.link')}
                    </Link>
                </div>
            </footer>
        </div>
    );
}
