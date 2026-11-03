import { NewTabHint } from '@/components/new-tab-hint';
import { useTranslation } from '@/hooks/use-translation';
import { privacy } from '@/routes';

// The privacy page in a new tab, from inside a form: what is already written stays where it is.
export function PrivacyLink() {
    const { t } = useTranslation();

    return (
        <a
            href={privacy.url()}
            target="_blank"
            rel="noopener noreferrer"
            className="underline underline-offset-4"
        >
            {t('privacy.link')}
            <NewTabHint />
        </a>
    );
}
