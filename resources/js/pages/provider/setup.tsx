import { Form, Head } from '@inertiajs/react';
import { ProviderProfileFields } from '@/components/provider-profile-fields';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
} from '@/components/ui/card';
import { Spinner } from '@/components/ui/spinner';
import { useTranslation } from '@/hooks/use-translation';
import { setup, store } from '@/routes/provider';

// "Complete your profile", for a logged-in user without a provider: the form creates it,
// not published. The fields are shared with the edit page (ProviderProfileFields).
export default function ProviderSetup() {
    const { t } = useTranslation();

    return (
        <>
            <Head title={t('provider.setup.title')} />
            <div className="p-4">
                <Card className="max-w-2xl">
                    <CardHeader>
                        <h1 className="text-2xl leading-none font-semibold">
                            {t('provider.setup.title')}
                        </h1>
                        <CardDescription>
                            {t('provider.setup.description')}
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <Form
                            {...store.form()}
                            options={{ preserveScroll: true }}
                            className="flex flex-col gap-6"
                        >
                            {({ processing, errors }) => (
                                <>
                                    <ProviderProfileFields errors={errors} />

                                    <Button
                                        type="submit"
                                        className="self-start"
                                        disabled={processing}
                                    >
                                        {processing && <Spinner />}
                                        {t('provider.setup.form.submit')}
                                    </Button>
                                </>
                            )}
                        </Form>
                    </CardContent>
                </Card>
            </div>
        </>
    );
}

// Same format as the dashboard: one breadcrumb.
ProviderSetup.layout = {
    breadcrumbs: [
        {
            titleKey: 'provider.setup.title',
            href: setup(),
        },
    ],
};
