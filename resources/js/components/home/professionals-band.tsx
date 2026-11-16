import { usePage } from '@inertiajs/react';
import { NewTabHint } from '@/components/new-tab-hint';
import { useReveal } from '@/hooks/use-reveal';
import { useTranslation } from '@/hooks/use-translation';
import { touchTarget } from '@/lib/touch-target';
import { cn } from '@/lib/utils';
import { whatsappLink } from '@/lib/whatsapp';

const TITLE_ID = 'home-professionals-title';

// The links of the band: brown and underlined as every link in a text, 44px on phones, the usual
// focus ring.
const LINK = cn(
    'rounded-sm font-semibold underline underline-offset-4 outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-hidden',
    touchTarget,
);

// The band for the professionals, at the bottom of the home page (docs/home.md): a title, a
// sentence and the ways to write to Griot, the site's own contacts (the shared prop "contact",
// config/contact.php, the same as the footer). With no contact configured, no band at all.
// A light card since the visual polish (the page had two big brown blocks): sand with brown text,
// rounded, a thin border and a 4px orange edge on the left (the brand, never an error: it says
// nothing). The band at the top of the page stays brown. It appears when scrolled to (useReveal),
// only without "reduce motion".
export function ProfessionalsBand() {
    const { t } = useTranslation();
    const { contact } = usePage().props;
    const reveal = useReveal<HTMLElement>();

    if (contact.email === null && contact.whatsapp_url === null) {
        return null;
    }

    return (
        <section
            ref={reveal}
            aria-labelledby={TITLE_ID}
            className="rounded-xl border border-l-4 border-l-brand bg-secondary text-secondary-foreground"
        >
            <div className="reveal-item flex flex-col gap-3 p-6 md:p-8">
                <h2 id={TITLE_ID} className="text-xl font-semibold">
                    {t('home.pros.title')}
                </h2>
                <p className="max-w-2xl">{t('home.pros.text')}</p>
                <div className="flex flex-wrap items-center gap-x-6 gap-y-2">
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
                </div>
            </div>
        </section>
    );
}
