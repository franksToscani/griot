import { Form, Head } from '@inertiajs/react';
import InputError from '@/components/input-error';
import PasswordInput from '@/components/password-input';
import TextLink from '@/components/text-link';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { register } from '@/routes';
import { store } from '@/routes/login';
import { request } from '@/routes/password';
import { useTranslation } from '@/hooks/use-translation';
import { errorId, fieldA11y, focusFirstInvalid } from '@/lib/form-a11y';
import { touchTarget } from '@/lib/touch-target';
import { cn } from '@/lib/utils';

type Props = {
    status?: string;
    canResetPassword: boolean;
};

export default function Login({ status, canResetPassword }: Props) {
    const { t } = useTranslation();

    return (
        <>
            <Head title={t('nav.login')} />

            <Form
                id="login-form"
                onError={() => focusFirstInvalid('login-form')}
                {...store.form()}
                resetOnSuccess={['password']}
                className="flex flex-col gap-6"
            >
                {({ processing, errors }) => (
                    <>
                        <div className="grid gap-6">
                            <div className="grid gap-2">
                                <Label htmlFor="email">
                                    {t('common.email_address')}
                                </Label>
                                <Input
                                    id="email"
                                    type="email"
                                    name="email"
                                    required
                                    autoFocus
                                    autoComplete="email"
                                    placeholder={t('common.email_placeholder')}
                                    {...fieldA11y('email', errors.email)}
                                />
                                <InputError
                                    id={errorId('email')}
                                    message={errors.email}
                                />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="password">
                                    {t('common.password')}
                                </Label>
                                <PasswordInput
                                    id="password"
                                    name="password"
                                    required
                                    autoComplete="current-password"
                                    placeholder={t('common.password')}
                                    {...fieldA11y('password', errors.password)}
                                />
                                <InputError
                                    id={errorId('password')}
                                    message={errors.password}
                                />
                                {/* After the field, in the order of the page: field, show password, then
                                    the way out when the password is lost. */}
                                {canResetPassword && (
                                    <TextLink
                                        href={request()}
                                        className={cn(
                                            'justify-self-end text-sm',
                                            touchTarget,
                                        )}
                                    >
                                        {t('account.login.forgot')}
                                    </TextLink>
                                )}
                            </div>

                            {/* A 44px row on phones, as the box's invisible area (ui/checkbox). */}
                            <div className="flex items-center space-x-3 max-md:min-h-11">
                                <Checkbox id="remember" name="remember" />
                                <Label htmlFor="remember">
                                    {t('account.login.remember')}
                                </Label>
                            </div>

                            <Button
                                type="submit"
                                className="mt-4 w-full"
                                disabled={processing}
                                data-test="login-button"
                            >
                                {processing && <Spinner />}
                                {t('nav.login')}
                            </Button>
                        </div>

                        <div className="text-center text-sm text-muted-foreground">
                            {t('account.login.no_account')}{' '}
                            <TextLink href={register()}>
                                {t('nav.register')}
                            </TextLink>
                        </div>
                    </>
                )}
            </Form>

            {/* Always on the page, so that the message is read when it appears. */}
            <div role="status">
                {status && (
                    <p className="mb-4 text-center text-sm font-medium text-green-700 dark:text-green-600">
                        {status}
                    </p>
                )}
            </div>
        </>
    );
}

Login.layout = {
    titleKey: 'account.login.title',
    descriptionKey: 'account.login.description',
};
