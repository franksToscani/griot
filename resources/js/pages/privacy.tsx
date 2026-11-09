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
    // From the environment (config/privacy.php): null when missing, never an invented value.
    controller: { name: string | null; email: string | null };
    // False until a competent person has checked the text.
    reviewed: boolean;
    // Y-m-d.
    updatedOn: string;
    retentionMonths: number;
    // How long before the start a customer can still cancel online.
    cancellationHours: number;
    cookies: {
        session: string;
        sessionMinutes: number;
        locale: string;
        // "remember_web_" and a hash, as Laravel names it; its duration in days.
        remember: string;
        rememberDays: number;
    };
};

// The privacy page, public, a draft (issue #131, docs/legal.md). Every text is a key privacy.*,
// one per paragraph; every number comes from the server, never written in a text.
export default function Privacy({
    controller,
    reviewed,
    updatedOn,
    retentionMonths,
    cancellationHours,
    cookies,
}: Props) {
    const { t, locale } = useTranslation();
    const sessionDuration = t('privacy.cookies.duration_session', {
        minutes: cookies.sessionMinutes,
    });

    // The names of the cookies set in the browser (use-appearance.tsx, ui/sidebar.tsx) and by
    // Laravel (XSRF-TOKEN); the session, the language and "remember me" come from the server.
    const cookieRows = [
        {
            name: cookies.session,
            purpose: t('privacy.cookies.session'),
            duration: sessionDuration,
        },
        {
            name: 'XSRF-TOKEN',
            purpose: t('privacy.cookies.xsrf'),
            duration: sessionDuration,
        },
        {
            name: cookies.remember,
            purpose: t('privacy.cookies.remember'),
            duration: t('privacy.cookies.duration_days', {
                days: cookies.rememberDays,
            }),
        },
        {
            name: cookies.locale,
            purpose: t('privacy.cookies.locale'),
            duration: t('privacy.cookies.duration_year'),
        },
        {
            name: 'appearance',
            purpose: t('privacy.cookies.appearance'),
            duration: t('privacy.cookies.duration_year'),
        },
        {
            name: 'sidebar_state',
            purpose: t('privacy.cookies.sidebar'),
            duration: t('privacy.cookies.duration_week'),
        },
    ];

    return (
        <>
            <Head title={t('privacy.title')} />
            <article className="mx-auto flex w-full max-w-2xl flex-col gap-8 text-sm leading-relaxed">
                {!reviewed && (
                    <DraftNotice
                        title={t('privacy.draft.title')}
                        body={t('privacy.draft.body')}
                    />
                )}

                <header className="flex flex-col gap-2">
                    <h1 className="text-2xl font-semibold">
                        {t('privacy.title')}
                    </h1>
                    <p>{t('privacy.intro')}</p>
                    <p className="text-muted-foreground">
                        {t('privacy.updated', {
                            date: formatLongDate(locale, updatedOn),
                        })}
                    </p>
                </header>

                <LegalSection title={t('privacy.controller.heading')}>
                    <dl className="grid gap-2 sm:grid-cols-[auto_1fr] sm:gap-x-6">
                        <dt className="font-medium">
                            {t('privacy.controller.name_label')}
                        </dt>
                        <dd>
                            {controller.name ?? (
                                <Placeholder>
                                    {t('privacy.controller.missing_name')}
                                </Placeholder>
                            )}
                        </dd>
                        <dt className="font-medium">
                            {t('privacy.controller.email_label')}
                        </dt>
                        <dd>
                            {controller.email !== null ? (
                                <a
                                    href={`mailto:${controller.email}`}
                                    className={cn(
                                        'underline underline-offset-4',
                                        touchTarget,
                                    )}
                                >
                                    {controller.email}
                                </a>
                            ) : (
                                <Placeholder>
                                    {t('privacy.controller.missing_email')}
                                </Placeholder>
                            )}
                        </dd>
                    </dl>
                    <p>{t('privacy.controller.roles')}</p>
                </LegalSection>

                <LegalSection title={t('privacy.data.heading')}>
                    <h3 className="font-medium">
                        {t('privacy.data.customers_title')}
                    </h3>
                    <p>{t('privacy.data.customers')}</p>
                    <p>{t('privacy.data.customers_by_provider')}</p>
                    <h3 className="font-medium">
                        {t('privacy.data.providers_title')}
                    </h3>
                    <p>{t('privacy.data.providers')}</p>
                    <h3 className="font-medium">
                        {t('privacy.data.technical_title')}
                    </h3>
                    <p>{t('privacy.data.technical')}</p>
                </LegalSection>

                <LegalSection title={t('privacy.purpose.heading')}>
                    <p>{t('privacy.purpose.customers')}</p>
                    <p>{t('privacy.purpose.providers')}</p>
                    <p>{t('privacy.purpose.technical')}</p>
                    <p>{t('privacy.purpose.no_marketing')}</p>
                </LegalSection>

                <LegalSection title={t('privacy.visibility.heading')}>
                    <p>{t('privacy.visibility.provider')}</p>
                    <p>{t('privacy.visibility.customer')}</p>
                    <p>{t('privacy.visibility.public')}</p>
                </LegalSection>

                <LegalSection title={t('privacy.photos.heading')}>
                    <p>{t('privacy.photos.body')}</p>
                    <p>{t('privacy.photos.consent')}</p>
                </LegalSection>

                <LegalSection title={t('privacy.retention.heading')}>
                    <p>
                        {t('privacy.retention.bookings', {
                            months: retentionMonths,
                        })}
                    </p>
                    <p>{t('privacy.retention.copies')}</p>
                    <p>{t('privacy.retention.providers')}</p>
                    <p>
                        {t('privacy.retention.technical', {
                            minutes: cookies.sessionMinutes,
                        })}
                    </p>
                </LegalSection>

                <LegalSection title={t('privacy.recipients.heading')}>
                    <ul className="flex list-disc flex-col gap-2 pl-5">
                        <li>{t('privacy.recipients.provider')}</li>
                        <li>{t('privacy.recipients.whatsapp')}</li>
                        <li>{t('privacy.recipients.hosting')}</li>
                        <li>{t('privacy.recipients.photos')}</li>
                        <li>{t('privacy.recipients.email')}</li>
                    </ul>
                    <p>{t('privacy.recipients.planned')}</p>
                    <p>{t('privacy.recipients.no_one_else')}</p>
                </LegalSection>

                <LegalSection title={t('privacy.rights.heading')}>
                    <p>{t('privacy.rights.body')}</p>
                    <p>
                        {t('privacy.rights.customers', {
                            hours: cancellationHours,
                        })}
                    </p>
                    <p>{t('privacy.rights.providers')}</p>
                    <p>{t('privacy.rights.authority')}</p>
                </LegalSection>

                <LegalSection title={t('privacy.sensitive.heading')}>
                    <p>{t('privacy.sensitive.body')}</p>
                </LegalSection>

                <LegalSection title={t('privacy.contact.heading')}>
                    <p>
                        {t('privacy.contact.body')}{' '}
                        {controller.email !== null ? (
                            <a
                                href={`mailto:${controller.email}`}
                                className="underline underline-offset-4"
                            >
                                {controller.email}
                            </a>
                        ) : (
                            <Placeholder>
                                {t('privacy.controller.missing_email')}
                            </Placeholder>
                        )}
                    </p>
                </LegalSection>

                <LegalSection title={t('privacy.cookies.heading')}>
                    <p>{t('privacy.cookies.intro')}</p>
                    <div className="overflow-x-auto">
                        <table className="w-full border-collapse text-left">
                            <thead>
                                <tr className="border-b">
                                    <th className="py-2 pr-4 font-medium">
                                        {t('privacy.cookies.name')}
                                    </th>
                                    <th className="py-2 pr-4 font-medium">
                                        {t('privacy.cookies.purpose')}
                                    </th>
                                    <th className="py-2 font-medium">
                                        {t('privacy.cookies.duration')}
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                {cookieRows.map((row) => (
                                    <tr
                                        key={row.name}
                                        className="border-b align-top"
                                    >
                                        {/* break-all: remember_web_ and its hash are one long word. */}
                                        <td className="py-2 pr-4 font-mono text-xs break-all">
                                            {row.name}
                                        </td>
                                        <td className="py-2 pr-4">
                                            {row.purpose}
                                        </td>
                                        <td className="py-2">{row.duration}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                    <p>{t('privacy.cookies.storage')}</p>
                    <p>{t('privacy.cookies.external')}</p>
                </LegalSection>
            </article>
        </>
    );
}
