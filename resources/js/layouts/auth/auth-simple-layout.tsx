import { Link, usePage } from '@inertiajs/react';
import AppLogoIcon from '@/components/app-logo-icon';
import { LocaleSwitcher } from '@/components/locale-switcher';
import { touchTarget } from '@/lib/touch-target';
import { cn } from '@/lib/utils';
import { home } from '@/routes';
import type { AuthLayoutProps } from '@/types';

export default function AuthSimpleLayout({
    children,
    title,
    description,
}: AuthLayoutProps) {
    const { name } = usePage().props;

    return (
        <div className="flex min-h-svh flex-col items-center justify-center gap-6 bg-background p-6 md:p-10">
            {/* The whole card is the content: only the logo comes before the fields, so no skip
                link here. */}
            <main className="w-full max-w-sm">
                <div className="flex flex-col gap-8">
                    <div className="flex flex-col items-center gap-4">
                        {/* The logo alone is 36x40: on phones the link is at least 44x44. */}
                        <Link
                            href={home()}
                            className={cn(
                                'flex flex-col items-center gap-2 font-medium',
                                touchTarget,
                            )}
                        >
                            <div className="mb-1 flex h-9 w-9 items-center justify-center rounded-md">
                                <AppLogoIcon className="size-9" />
                            </div>
                            {/* The link goes home: its name is the app's, as the logo of the
                                public header. The page's title is the h1 below. */}
                            <span className="sr-only">{name}</span>
                        </Link>

                        <div className="space-y-2 text-center">
                            <h1 className="text-xl font-medium">{title}</h1>
                            <p className="text-center text-sm text-muted-foreground">
                                {description}
                            </p>
                        </div>
                    </div>
                    {children}
                    <LocaleSwitcher className="justify-center" />
                </div>
            </main>
        </div>
    );
}
