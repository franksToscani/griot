// Components
import { Form, Head } from '@inertiajs/react';
import TextLink from '@/components/text-link';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { logout } from '@/routes';
import { send } from '@/routes/verification';
import { useTranslation } from '@/hooks/use-translation';
import { touchTarget } from '@/lib/touch-target';
import { cn } from '@/lib/utils';

export default function VerifyEmail({ status }: { status?: string }) {
    const { t } = useTranslation();

    return (
        <>
            <Head title={t('account.verify.title')} />

            {/* Always on the page, so that the message is read when it appears. */}
            <div role="status">
                {status === 'verification-link-sent' && (
                    <p className="mb-4 text-center text-sm font-medium text-green-700 dark:text-green-600">
                        {t('account.verify.sent')}
                    </p>
                )}
            </div>

            <Form {...send.form()} className="space-y-6 text-center">
                {({ processing }) => (
                    <>
                        <Button disabled={processing} variant="secondary">
                            {processing && <Spinner />}
                            {t('account.verify.resend')}
                        </Button>

                        {/* Centred on the page: on phones "Esci" is narrower than its 44px box,
                            so the word is centred in the box too. */}
                        <TextLink
                            href={logout()}
                            className={cn(
                                'mx-auto block text-sm max-md:justify-center',
                                touchTarget,
                            )}
                        >
                            {t('nav.logout')}
                        </TextLink>
                    </>
                )}
            </Form>
        </>
    );
}

VerifyEmail.layout = {
    titleKey: 'account.verify.title',
    descriptionKey: 'account.verify.description',
};
