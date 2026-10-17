import { usePage } from '@inertiajs/react';

export type TranslateFn = (
    key: string,
    replacements?: Record<string, string | number>,
) => string;

export type UseTranslationReturn = {
    locale: string;
    t: TranslateFn;
};

export function useTranslation(): UseTranslationReturn {
    const { locale, translations } = usePage().props;

    const t: TranslateFn = (key, replacements = {}) => {
        // A missing key shows the key itself, so a gap is visible at once.
        let text = translations[key] ?? key;

        // Same :name placeholders as Laravel's __() on the PHP side.
        for (const [name, value] of Object.entries(replacements)) {
            text = text.split(`:${name}`).join(String(value));
        }

        return text;
    };

    return { locale, t };
}
