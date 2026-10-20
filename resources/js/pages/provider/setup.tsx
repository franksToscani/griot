import { Form, Head } from '@inertiajs/react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
} from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import { useTranslation } from '@/hooks/use-translation';
import { setup, store } from '@/routes/provider';

// "Complete your profile", for a logged-in user without a provider: the form creates it,
// not published. The fields are not controlled: after a validation error the values stay.
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
                                    <div className="grid gap-2">
                                        <Label htmlFor="name">
                                            {t('provider.setup.form.name')}
                                        </Label>
                                        <Input
                                            id="name"
                                            name="name"
                                            required
                                            maxLength={150}
                                            autoComplete="organization"
                                        />
                                        <InputError message={errors.name} />
                                    </div>

                                    <div className="grid gap-2">
                                        <Label htmlFor="city">
                                            {t('provider.setup.form.city')}
                                        </Label>
                                        <Input
                                            id="city"
                                            name="city"
                                            required
                                            maxLength={100}
                                            autoComplete="address-level2"
                                        />
                                        <InputError message={errors.city} />
                                    </div>

                                    <div className="grid gap-2">
                                        <Label htmlFor="bio">
                                            {t('provider.setup.form.bio')}
                                        </Label>
                                        <Textarea
                                            id="bio"
                                            name="bio"
                                            maxLength={1000}
                                            autoComplete="off"
                                        />
                                        <InputError message={errors.bio} />
                                    </div>

                                    <div className="grid gap-2">
                                        <Label htmlFor="whatsapp">
                                            {t('provider.setup.form.whatsapp')}
                                        </Label>
                                        <Input
                                            id="whatsapp"
                                            name="whatsapp"
                                            type="tel"
                                            maxLength={30}
                                            autoComplete="tel"
                                            aria-describedby="whatsapp-help"
                                        />
                                        <p
                                            id="whatsapp-help"
                                            className="text-sm text-muted-foreground"
                                        >
                                            {t(
                                                'provider.setup.form.whatsapp_help',
                                            )}
                                        </p>
                                        <InputError message={errors.whatsapp} />
                                    </div>

                                    <div className="grid gap-2">
                                        <Label htmlFor="instagram">
                                            {t('provider.setup.form.instagram')}
                                        </Label>
                                        {/* 31: one more than the limit, for the "@" that the server removes. */}
                                        <Input
                                            id="instagram"
                                            name="instagram"
                                            maxLength={31}
                                            autoComplete="off"
                                            aria-describedby="instagram-help"
                                        />
                                        <p
                                            id="instagram-help"
                                            className="text-sm text-muted-foreground"
                                        >
                                            {t(
                                                'provider.setup.form.instagram_help',
                                            )}
                                        </p>
                                        <InputError
                                            message={errors.instagram}
                                        />
                                    </div>

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
