import { Head } from '@inertiajs/react';
import {
    DraftNotice,
    LegalSection,
    Placeholder,
} from '@/components/legal-page';
import { useTranslation } from '@/hooks/use-translation';
import { formatLongDate } from '@/lib/format';
import { touchTarget } from '@/lib/touch-target';
import { cn } from '@/lib/utils';

type Props = {
    // From the environment (config/terms.php) and the site's contact (config/contact.php): null
    // when missing, never an invented value.
    publisher: {
        name: string | null;
        address: string | null;
        vatNumber: string | null;
        email: string | null;
    };
    // False until a competent person has checked the text.
    reviewed: boolean;
    // Y-m-d.
    updatedOn: string;
    // How long before the start a customer can still cancel online.
    cancellationHours: number;
};

// The terms of use, public, a draft like the privacy page (issue #131, docs/legal.md). Every text
// is a key terms.*, one per paragraph; the numbers come from the server.
export default function Terms({
    publisher,
    reviewed,
    updatedOn,
    cancellationHours,
}: Props) {
    const { t, locale } = useTranslation();

    return (
        <>
            <Head title={t('terms.title')} />
            <article className="mx-auto flex w-full max-w-2xl flex-col gap-8 text-sm leading-relaxed">
                {!reviewed && (
                    <DraftNotice
                        title={t('terms.draft.title')}
                        body={t('terms.draft.body')}
                    />
                )}

                <header className="flex flex-col gap-2">
                    <h1 className="text-2xl font-semibold">
                        {t('terms.title')}
                    </h1>
                    <p>{t('terms.intro')}</p>
                    <p className="text-muted-foreground">
                        {t('terms.updated', {
                            date: formatLongDate(locale, updatedOn),
                        })}
                    </p>
                </header>

                <LegalSection title={t('terms.service.heading')}>
                    <p>{t('terms.service.body')}</p>
                </LegalSection>

                <LegalSection title={t('terms.payments.heading')}>
                    <p>{t('terms.payments.body')}</p>
                </LegalSection>

                <LegalSection title={t('terms.price.heading')}>
                    <p>{t('terms.price.body')}</p>
                </LegalSection>

                <LegalSection title={t('terms.providers.heading')}>
                    <p>{t('terms.providers.body')}</p>
                </LegalSection>

                <LegalSection title={t('terms.cancellation.heading')}>
                    <p>
                        {t('terms.cancellation.body', {
                            hours: cancellationHours,
                        })}
                    </p>
                </LegalSection>

                <LegalSection title={t('terms.content.heading')}>
                    <p>{t('terms.content.body')}</p>
                </LegalSection>

                <LegalSection title={t('terms.use.heading')}>
                    <p>{t('terms.use.body')}</p>
                </LegalSection>

                <LegalSection title={t('terms.publisher.heading')}>
                    <dl className="grid gap-2 sm:grid-cols-[auto_1fr] sm:gap-x-6">
                        <dt className="font-medium">
                            {t('terms.publisher.name_label')}
                        </dt>
                        <dd>
                            {publisher.name ?? (
                                <Placeholder>
                                    {t('terms.publisher.missing_name')}
                                </Placeholder>
                            )}
                        </dd>
                        <dt className="font-medium">
                            {t('terms.publisher.address_label')}
                        </dt>
                        <dd>
                            {publisher.address ?? (
                                <Placeholder>
                                    {t('terms.publisher.missing_address')}
                                </Placeholder>
                            )}
                        </dd>
                        <dt className="font-medium">
                            {t('terms.publisher.vat_label')}
                        </dt>
                        <dd>
                            {publisher.vatNumber ?? (
                                <Placeholder>
                                    {t('terms.publisher.missing_vat')}
                                </Placeholder>
                            )}
                        </dd>
                        <dt className="font-medium">
                            {t('terms.publisher.email_label')}
                        </dt>
                        <dd>
                            {publisher.email !== null ? (
                                <a
                                    href={`mailto:${publisher.email}`}
                                    className={cn(
                                        'underline underline-offset-4',
                                        touchTarget,
                                    )}
                                >
                                    {publisher.email}
                                </a>
                            ) : (
                                <Placeholder>
                                    {t('terms.publisher.missing_email')}
                                </Placeholder>
                            )}
                        </dd>
                    </dl>
                </LegalSection>

                <LegalSection title={t('terms.law.heading')}>
                    <p>
                        <Placeholder>{t('terms.law.missing')}</Placeholder>
                    </p>
                </LegalSection>
            </article>
        </>
    );
}
