import { Form, Head } from '@inertiajs/react';
import InputError from '@/components/input-error';
import PasswordInput from '@/components/password-input';
import { PrivacyLink } from '@/components/privacy-link';
import TextLink from '@/components/text-link';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { login } from '@/routes';
import { store } from '@/routes/register';
import { useTranslation } from '@/hooks/use-translation';
import { errorId, fieldA11y, focusFirstInvalid } from '@/lib/form-a11y';

type Props = {
    passwordRules: string;
};

export default function Register({ passwordRules }: Props) {
    const { t } = useTranslation();

    return (
        <>
            <Head title={t('nav.register')} />
            <Form
                id="register-form"
                onError={() => focusFirstInvalid('register-form')}
                {...store.form()}
                resetOnSuccess={['password', 'password_confirmation']}
                disableWhileProcessing
                className="flex flex-col gap-6"
            >
                {({ processing, errors }) => (
                    <>
                        <div className="grid gap-6">
                            <div className="grid gap-2">
                                <Label htmlFor="name">{t('common.name')}</Label>
                                <Input
                                    id="name"
                                    type="text"
                                    required
                                    autoFocus
                                    autoComplete="name"
                                    name="name"
                                    placeholder={t('common.full_name')}
                                    {...fieldA11y('name', errors.name)}
                                />
                                <InputError
                                    id={errorId('name')}
                                    message={errors.name}
                                    className="mt-2"
                                />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="email">
                                    {t('common.email_address')}
                                </Label>
                                <Input
                                    id="email"
                                    type="email"
                                    required
                                    autoComplete="email"
                                    name="email"
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
                                    required
                                    autoComplete="new-password"
                                    name="password"
                                    placeholder={t('common.password')}
                                    passwordrules={passwordRules}
                                    {...fieldA11y('password', errors.password)}
                                />
                                <InputError
                                    id={errorId('password')}
                                    message={errors.password}
                                />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="password_confirmation">
                                    {t('common.confirm_password')}
                                </Label>
                                <PasswordInput
                                    id="password_confirmation"
                                    required
                                    autoComplete="new-password"
                                    name="password_confirmation"
                                    placeholder={t('common.confirm_password')}
                                    passwordrules={passwordRules}
                                    {...fieldA11y(
                                        'password_confirmation',
                                        errors.password_confirmation,
                                    )}
                                />
                                <InputError
                                    id={errorId('password_confirmation')}
                                    message={errors.password_confirmation}
                                />
                            </div>

                            <Button
                                type="submit"
                                className="mt-2 w-full"
                                data-test="register-user-button"
                            >
                                {processing && <Spinner />}
                                {t('account.register.submit')}
                            </Button>

                            <p className="text-center text-xs text-muted-foreground">
                                {t('account.register.privacy_note')}{' '}
                                <PrivacyLink />
                            </p>
                        </div>

                        <div className="text-center text-sm text-muted-foreground">
                            {t('account.register.have_account')}{' '}
                            <TextLink href={login()}>{t('nav.login')}</TextLink>
                        </div>
                    </>
                )}
            </Form>
        </>
    );
}

Register.layout = {
    titleKey: 'account.register.title',
    descriptionKey: 'account.register.description',
};
