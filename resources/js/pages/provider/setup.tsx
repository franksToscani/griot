import { Head } from '@inertiajs/react';
import {
    Card,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { useTranslation } from '@/hooks/use-translation';
import { setup } from '@/routes/provider';

// "Complete your profile", for a logged-in user without a provider. The form comes with issue #34.
export default function ProviderSetup() {
    const { t } = useTranslation();

    return (
        <>
            <Head title={t('provider.setup.title')} />
            <div className="p-4">
                <Card className="max-w-2xl">
                    <CardHeader>
                        <CardTitle>
                            <h1 className="text-2xl font-semibold">
                                {t('provider.setup.title')}
                            </h1>
                        </CardTitle>
                        <CardDescription>
                            {t('provider.setup.description')}
                        </CardDescription>
                    </CardHeader>
                </Card>
            </div>
        </>
    );
}

// Same format as the dashboard; like 'Dashboard', the breadcrumb is a kit text in English
// until the kit is translated (issue #54).
ProviderSetup.layout = {
    breadcrumbs: [
        {
            title: 'Profile setup',
            href: setup(),
        },
    ],
};
