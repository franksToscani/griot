import { Form, usePage } from '@inertiajs/react';
import { useRef } from 'react';
import ProfileController from '@/actions/App/Http/Controllers/Settings/ProfileController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import PasswordInput from '@/components/password-input';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { useTranslation } from '@/hooks/use-translation';
import { touchTarget } from '@/lib/touch-target';
import { cn } from '@/lib/utils';

// The explanation that replaces the button for a provider with bookings (issue #133). The focus
// goes there when the server refuses while the window is open: the window disappears with the
// button, and the focus must not be lost.
const BLOCKED_ID = 'delete-user-blocked';

// A provider with bookings, in any status and anonymous ones too, cannot delete the account: the
// bookings keep the customers' private links and the provider's history. Instead of asking the
// password and then refusing, the page says why and gives the site's contact (config/contact.php).
// The server refuses anyway (ProfileDeleteRequest, DeleteProviderAccount).
export default function DeleteUser({ hasBookings }: { hasBookings: boolean }) {
    const { t } = useTranslation();
    const { contact } = usePage().props;

    const passwordInput = useRef<HTMLInputElement>(null);

    if (hasBookings) {
        return (
            <div className="space-y-6">
                <Heading
                    variant="small"
                    title={t('settings.delete.title')}
                    description={t('settings.delete.description')}
                />
                <div
                    id={BLOCKED_ID}
                    tabIndex={-1}
                    className="space-y-2 rounded-lg border p-4 text-sm"
                    data-test="delete-user-blocked"
                >
                    <p>{t('settings.delete.has_bookings')}</p>
                    <p>
                        {contact.email !== null ? (
                            <>
                                {t('settings.delete.has_bookings_write')}{' '}
                                <a
                                    href={`mailto:${contact.email}`}
                                    className={cn(
                                        'underline underline-offset-4',
                                        touchTarget,
                                    )}
                                >
                                    {contact.email}
                                </a>
                                .
                            </>
                        ) : (
                            t('settings.delete.has_bookings_no_contact')
                        )}
                    </p>
                </div>
            </div>
        );
    }

    return (
        <div className="space-y-6">
            <Heading
                variant="small"
                title={t('settings.delete.title')}
                description={t('settings.delete.description')}
            />
            <div className="space-y-4 rounded-lg border border-red-100 bg-red-50 p-4 dark:border-red-200/10 dark:bg-red-700/10">
                <div className="relative space-y-0.5 text-red-700 dark:text-red-100">
                    <p className="font-medium">
                        {t('settings.delete.warning')}
                    </p>
                    <p className="text-sm">
                        {t('settings.delete.warning_text')}
                    </p>
                </div>

                <Dialog>
                    <DialogTrigger asChild>
                        <Button
                            variant="destructive"
                            data-test="delete-user-button"
                        >
                            {t('settings.delete.title')}
                        </Button>
                    </DialogTrigger>
                    <DialogContent>
                        <DialogTitle>
                            {t('settings.delete.confirm_title')}
                        </DialogTitle>
                        <DialogDescription>
                            {t('settings.delete.confirm_text')}
                        </DialogDescription>

                        <Form
                            {...ProfileController.destroy.form()}
                            options={{
                                preserveScroll: true,
                            }}
                            onError={(errors) => {
                                // Refused for the bookings (one arrived meanwhile): the page now
                                // shows the explanation in place of the window.
                                if (errors.account) {
                                    requestAnimationFrame(() =>
                                        document
                                            .getElementById(BLOCKED_ID)
                                            ?.focus(),
                                    );

                                    return;
                                }

                                passwordInput.current?.focus();
                            }}
                            resetOnSuccess
                            className="space-y-6"
                        >
                            {({ resetAndClearErrors, processing, errors }) => (
                                <>
                                    <div className="grid gap-2">
                                        <Label
                                            htmlFor="password"
                                            className="sr-only"
                                        >
                                            {t('common.password')}
                                        </Label>

                                        <PasswordInput
                                            id="password"
                                            name="password"
                                            ref={passwordInput}
                                            placeholder={t('common.password')}
                                            autoComplete="current-password"
                                        />

                                        <InputError message={errors.password} />
                                    </div>

                                    <DialogFooter className="gap-2">
                                        <DialogClose asChild>
                                            <Button
                                                variant="secondary"
                                                onClick={() =>
                                                    resetAndClearErrors()
                                                }
                                            >
                                                {t('common.cancel')}
                                            </Button>
                                        </DialogClose>

                                        <Button
                                            variant="destructive"
                                            disabled={processing}
                                            asChild
                                        >
                                            <button
                                                type="submit"
                                                data-test="confirm-delete-user-button"
                                            >
                                                {t('settings.delete.title')}
                                            </button>
                                        </Button>
                                    </DialogFooter>
                                </>
                            )}
                        </Form>
                    </DialogContent>
                </Dialog>
            </div>
        </div>
    );
}
