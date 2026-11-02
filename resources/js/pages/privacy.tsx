import { Head } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { useTranslation } from '@/hooks/use-translation';
import { formatLongDate } from '@/lib/format';

type Props = {
    // From the environment (config/privacy.php): null when missing, never an invented value.
    controller: { name: string | null; email: string | null };
    // False until a competent person has checked the text.
    reviewed: boolean;
    // Y-m-d.
    updatedOn: string;
    retentionMonths: number;
    cookies: { session: string; sessionMinutes: number; locale: string };
};

// A missing value of the configuration, shown so that nobody can miss it.
function Placeholder({ children }: { children: ReactNode }) {
    return (
        <span className="rounded bg-amber-100 px-1 font-medium text-amber-900 dark:bg-amber-900 dark:text-amber-100">
            {children}
        </span>
    );
}

function Section({ title, children }: { title: string; children: ReactNode }) {
    return (
        <section className="flex flex-col gap-3">
            <h2 className="text-lg font-semibold">{title}</h2>
            {children}
        </section>
    );
}

// The privacy page, public. Every text is a key privacy.*, one per paragraph.
export default function Privacy({
    controller,
    reviewed,
    updatedOn,
    retentionMonths,
    cookies,
}: Props) {
    const { t, locale } = useTranslation();
    const sessionDuration = t('privacy.cookies.duration_session', {
        minutes: cookies.sessionMinutes,
    });

    // The names of the cookies set in the browser (use-appearance.tsx, ui/sidebar.tsx) and by
    // Laravel (XSRF-TOKEN); the session and the language come from the server.
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
                    <Alert className="border-amber-500 bg-amber-50 text-amber-950 dark:bg-amber-950 dark:text-amber-50">
                        <AlertTitle>{t('privacy.draft.title')}</AlertTitle>
                        <AlertDescription className="text-current">
                            {t('privacy.draft.body')}
                        </AlertDescription>
                    </Alert>
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

                <Section title={t('privacy.controller.heading')}>
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
                                    className="underline underline-offset-4"
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
                    <p>{t('privacy.controller.providers')}</p>
                </Section>

                <Section title={t('privacy.data.heading')}>
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
                </Section>

                <Section title={t('privacy.purpose.heading')}>
                    <p>{t('privacy.purpose.customers')}</p>
                    <p>{t('privacy.purpose.providers')}</p>
                    <p>{t('privacy.purpose.technical')}</p>
                    <p>{t('privacy.purpose.no_marketing')}</p>
                </Section>

                <Section title={t('privacy.retention.heading')}>
                    <p>
                        {t('privacy.retention.bookings', {
                            months: retentionMonths,
                        })}
                    </p>
                    <p>{t('privacy.retention.providers')}</p>
                    <p>
                        {t('privacy.retention.technical', {
                            minutes: cookies.sessionMinutes,
                        })}
                    </p>
                </Section>

                <Section title={t('privacy.recipients.heading')}>
                    <ul className="flex list-disc flex-col gap-2 pl-5">
                        <li>{t('privacy.recipients.provider')}</li>
                        <li>{t('privacy.recipients.whatsapp')}</li>
                        <li>{t('privacy.recipients.hosting')}</li>
                        <li>{t('privacy.recipients.email')}</li>
                    </ul>
                    <p>{t('privacy.recipients.no_one_else')}</p>
                </Section>

                <Section title={t('privacy.rights.heading')}>
                    <p>{t('privacy.rights.body')}</p>
                    <p>{t('privacy.rights.providers')}</p>
                    <p>{t('privacy.rights.authority')}</p>
                </Section>

                <Section title={t('privacy.contact.heading')}>
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
                </Section>

                <Section title={t('privacy.cookies.heading')}>
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
                                        <td className="py-2 pr-4 font-mono text-xs">
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
                </Section>
            </article>
        </>
    );
}
