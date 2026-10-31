import { Link, usePage } from '@inertiajs/react';
import type { ReactNode } from 'react';
import AppLogo from '@/components/app-logo';
import { LocaleSwitcher } from '@/components/locale-switcher';
import { useTranslation } from '@/hooks/use-translation';
import { dashboard, home, login } from '@/routes';

// Layout for the public pages that customers see without logging in.
export default function PublicLayout({ children }: { children: ReactNode }) {
    const { auth } = usePage().props;
    const { t } = useTranslation();

    return (
        <div className="flex min-h-svh flex-col bg-background">
            <header className="border-b">
                <div className="mx-auto flex w-full max-w-6xl items-center justify-between gap-4 px-4 py-3">
                    <Link href={home()} className="flex items-center">
                        <AppLogo />
                    </Link>
                    <div className="flex items-center gap-4">
                        <LocaleSwitcher />
                        <nav className="text-sm">
                            {auth.user ? (
                                <Link href={dashboard()}>
                                    {t('nav.dashboard')}
                                </Link>
                            ) : (
                                <Link href={login()}>{t('nav.login')}</Link>
                            )}
                        </nav>
                    </div>
                </div>
            </header>
            <main className="mx-auto w-full max-w-6xl flex-1 px-4 py-6">
                {children}
            </main>
        </div>
    );
}
