import { Form, Head, usePage } from '@inertiajs/react';
import { Link } from '@inertiajs/react';
import ProfileController from '@/actions/App/Http/Controllers/Settings/ProfileController';
import DeleteUser from '@/components/delete-user';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { touchTarget } from '@/lib/touch-target';
import { edit } from '@/routes/profile';
import { edit as editProvider } from '@/routes/provider';
import type { Auth } from '@/types';
import { send } from '@/routes/verification';
import { useTranslation } from '@/hooks/use-translation';

type PageProps = {
    auth: Auth;
};

// The style of the links of this page (as "send the verification again").
const LINK =
    'text-foreground underline decoration-input underline-offset-4 transition-colors duration-300 ease-out hover:decoration-current!';

// One name for a provider, the business one (providers.name): shown here, changed only in the
// profile. The sentence is translated whole; the name, written by the provider, takes the place
// of ":name". A link that stands alone: 44px on phones (touchTarget).
function BusinessName({ name }: { name: string }) {
    const { t } = useTranslation();
    const [before, after = ''] = t('settings.profile.business_name').split(
        ':name',
    );

    return (
        <div className="grid gap-2">
            <p className="text-sm">
                {before}
                <strong className="font-semibold">{name}</strong>
                {after}
            </p>
            <Link
                href={editProvider()}
                className={`self-start text-sm ${LINK} ${touchTarget}`}
            >
                {t('settings.profile.business_name_link')}
            </Link>
        </div>
    );
}

export default function Profile({
    mustVerifyEmail,
    status,
    has_bookings,
    provider_name,
}: {
    mustVerifyEmail: boolean;
    status?: string;
    // A provider with bookings cannot delete the account (issue #133).
    has_bookings: boolean;
    // The business name of a provider, or null: then the field "Nome" of the account.
    provider_name: string | null;
}) {
    const { t } = useTranslation();

    const { auth } = usePage<PageProps>().props;
    const user = auth.user;

    // Behind the login: never without a user, but the shared prop says it can be null.
    if (user === null) {
        return null;
    }

    return (
        <>
            <Head title={t('settings.profile.head')} />

            <h1 className="sr-only">{t('settings.profile.head')}</h1>

            <div className="space-y-6">
                <Heading
                    variant="small"
                    title={t('settings.nav.profile')}
                    description={t('settings.profile.description')}
                />

                <Form
                    {...ProfileController.update.form()}
                    options={{
                        preserveScroll: true,
                    }}
                    className="space-y-6"
                >
                    {({ processing, errors }) => (
                        <>
                            {provider_name !== null ? (
                                <BusinessName name={provider_name} />
                            ) : (
                                <div className="grid gap-2">
                                    <Label htmlFor="name">
                                        {t('common.name')}
                                    </Label>

                                    <Input
                                        id="name"
                                        className="mt-1 block w-full"
                                        defaultValue={user.name}
                                        name="name"
                                        required
                                        autoComplete="name"
                                        placeholder={t('common.full_name')}
                                    />

                                    <InputError
                                        className="mt-2"
                                        message={errors.name}
                                    />
                                </div>
                            )}

                            <div className="grid gap-2">
                                <Label htmlFor="email">
                                    {t('common.email_address')}
                                </Label>

                                <Input
                                    id="email"
                                    type="email"
                                    className="mt-1 block w-full"
                                    defaultValue={user.email}
                                    name="email"
                                    required
                                    autoComplete="username"
                                    placeholder={t('common.email_address')}
                                />

                                <InputError
                                    className="mt-2"
                                    message={errors.email}
                                />
                            </div>

                            {mustVerifyEmail &&
                                user.email_verified_at === null && (
                                    <div>
                                        <p className="-mt-4 text-sm text-muted-foreground">
                                            {t('settings.profile.unverified')}{' '}
                                            <Link
                                                href={send()}
                                                as="button"
                                                className="text-foreground underline decoration-input underline-offset-4 transition-colors duration-300 ease-out hover:decoration-current!"
                                            >
                                                {t('settings.profile.resend')}
                                            </Link>
                                        </p>

                                        {/* Always on the page, so that the message is read when it appears. */}
                                        <div role="status">
                                            {status ===
                                                'verification-link-sent' && (
                                                <p className="mt-2 text-sm font-medium text-green-700 dark:text-green-600">
                                                    {t(
                                                        'settings.profile.link_sent',
                                                    )}
                                                </p>
                                            )}
                                        </div>
                                    </div>
                                )}

                            <div className="flex items-center gap-4">
                                <Button
                                    disabled={processing}
                                    data-test="update-profile-button"
                                >
                                    {t('common.save')}
                                </Button>
                            </div>
                        </>
                    )}
                </Form>
            </div>

            <DeleteUser hasBookings={has_bookings} />
        </>
    );
}

Profile.layout = {
    breadcrumbs: [
        {
            titleKey: 'settings.profile.head',
            href: edit(),
        },
    ],
};
