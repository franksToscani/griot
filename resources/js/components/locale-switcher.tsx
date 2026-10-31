import { router, usePage } from '@inertiajs/react';
import { useTranslation } from '@/hooks/use-translation';
import { cn } from '@/lib/utils';
// Explicit name: "update" alone says nothing at the call site.
import { update as updateLocale } from '@/routes/locale';

// Copied from services/index.tsx: a native select with the same look as the kit's Input.
const selectClass =
    'border-input flex h-9 w-auto min-w-0 rounded-md border bg-transparent px-3 py-1 text-base shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 md:text-sm';

// The language of the site, remembered by the browser in a cookie the server writes. Hidden while
// only one language is available: the list comes from config/app.php (available_locales).
export function LocaleSwitcher({ className }: { className?: string }) {
    const { t, locale } = useTranslation();
    const { locales } = usePage().props;

    if (locales.length <= 1) {
        return null;
    }

    return (
        <label className={cn('flex items-center gap-2 text-sm', className)}>
            <span className="sr-only">{t('common.locale.label')}</span>
            <select
                value={locale}
                onChange={(event) =>
                    // The answer goes back to the same page, now in the new language.
                    router.post(
                        updateLocale.url(),
                        { locale: event.target.value },
                        { preserveScroll: true },
                    )
                }
                className={selectClass}
            >
                {/* Each language in its own name: never translated. */}
                {locales.map((option) => (
                    <option
                        key={option.code}
                        value={option.code}
                        lang={option.code}
                    >
                        {option.name}
                    </option>
                ))}
            </select>
        </label>
    );
}
