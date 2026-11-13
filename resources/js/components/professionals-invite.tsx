import { usePage } from '@inertiajs/react';
import { NewTabHint } from '@/components/new-tab-hint';
import { useTranslation } from '@/hooks/use-translation';
import { touchTarget } from '@/lib/touch-target';
import { cn } from '@/lib/utils';
import { whatsappLink } from '@/lib/whatsapp';

const LINK = cn('underline underline-offset-4', touchTarget);

// The invitation for the professionals in the empty list of a new site (docs/providers-list.md):
// "Sei un professionista? Scrivici:" with the site's own contacts, the shared prop "contact" as in
// the footer and in the band of the home page. Only when the list is empty without a search or a
// filter, and only with a contact configured. Inside the status of the results, so it is read
// with the empty state.
export function ProfessionalsInvite({ empty }: { empty: boolean }) {
    const { t } = useTranslation();
    const { contact } = usePage().props;

    if (!empty || (contact.email === null && contact.whatsapp_url === null)) {
        return null;
    }

    return (
        <span className="mt-2 flex flex-wrap items-center gap-x-4 gap-y-1">
            <span>{t('providers.list.empty_pros')}</span>
            {contact.email !== null && (
                <a href={`mailto:${contact.email}`} className={LINK}>
                    {t('home.pros.email')}
                </a>
            )}
            {contact.whatsapp_url !== null && (
                <a
                    href={whatsappLink(
                        contact.whatsapp_url,
                        t('home.pros.whatsapp_message'),
                    )}
                    target="_blank"
                    rel="noopener noreferrer"
                    className={LINK}
                >
                    {t('home.pros.whatsapp')}
                    <NewTabHint />
                </a>
            )}
        </span>
    );
}
