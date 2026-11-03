import { router, usePage } from '@inertiajs/react';
import { useId } from 'react';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { useTranslation } from '@/hooks/use-translation';
import { cn } from '@/lib/utils';
// Explicit name: "update" alone says nothing at the call site.
import { update as updateLocale } from '@/routes/locale';

// The language of the site, remembered by the browser in a cookie the server writes. Hidden while
// only one language is available: the list comes from config/app.php (available_locales).
//
// A Radix Select, as the filters of the providers' list: the arrows move inside the open list and
// the page changes only when a language is confirmed (Enter, or a click). The native select changes
// its value with the arrows while closed, and every change was a new page.
export function LocaleSwitcher({ className }: { className?: string }) {
    const { t, locale } = useTranslation();
    const { locales } = usePage().props;
    // One per layout, but unique anyway: the label points to its own trigger.
    const id = useId();

    if (locales.length <= 1) {
        return null;
    }

    return (
        <div className={cn('flex items-center gap-2 text-sm', className)}>
            <Label htmlFor={id} className="sr-only">
                {t('common.locale.label')}
            </Label>
            <Select
                value={locale}
                onValueChange={(value) =>
                    // The answer goes back to the same page, now in the new language.
                    router.post(
                        updateLocale.url(),
                        { locale: value },
                        { preserveScroll: true },
                    )
                }
            >
                <SelectTrigger id={id} className="w-auto">
                    <SelectValue />
                </SelectTrigger>
                <SelectContent>
                    {/* Each language in its own name: never translated. */}
                    {locales.map((option) => (
                        <SelectItem
                            key={option.code}
                            value={option.code}
                            lang={option.code}
                        >
                            {option.name}
                        </SelectItem>
                    ))}
                </SelectContent>
            </Select>
        </div>
    );
}
