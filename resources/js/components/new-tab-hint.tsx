import { useTranslation } from '@/hooks/use-translation';

// Read by screen readers at the end of a link with target="_blank": "(si apre in una nuova scheda)".
// Hidden on screen: the link looks as before.
export function NewTabHint() {
    const { t } = useTranslation();

    return <span className="sr-only"> {t('privacy.new_tab')}</span>;
}
