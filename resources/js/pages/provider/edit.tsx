import { Form, Head } from '@inertiajs/react';
import InputError from '@/components/input-error';
import { ProviderProfileFields } from '@/components/provider-profile-fields';
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
import { useTranslation } from '@/hooks/use-translation';
import { dashboard } from '@/routes';
import { edit, update } from '@/routes/provider';
import type { ProviderEdit } from '@/types';

type Props = {
    provider: ProviderEdit;
};

// The logged-in provider edits its profile: the shared fields, plus the address of the page.
export default function ProviderEditPage({ provider }: Props) {
    const { t } = useTranslation();

    return (
        <>
            <Head title={t('provider.edit.title')} />
            <div className="p-4">
                <Card className="max-w-2xl">
                    <CardHeader>
                        <h1 className="text-2xl leading-none font-semibold">
                            {t('provider.edit.title')}
                        </h1>
                        <CardDescription>
                            {t('provider.edit.description')}
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <Form
                            {...update.form()}
                            options={{ preserveScroll: true }}
                            className="flex flex-col gap-6"
                        >
                            {({ processing, errors }) => (
                                <>
                                    <ProviderProfileFields
                                        defaults={provider}
                                        errors={errors}
                                    />

                                    <div className="grid gap-2">
                                        <Label htmlFor="slug">
                                            {t('provider.edit.slug')}
                                        </Label>
                                        <Input
                                            id="slug"
                                            name="slug"
                                            required
                                            maxLength={120}
                                            autoComplete="off"
                                            aria-describedby="slug-help"
                                            defaultValue={provider.slug}
                                        />
                                        <p
                                            id="slug-help"
                                            className="text-sm text-muted-foreground"
                                        >
                                            {t('provider.edit.slug_help')}
                                        </p>
                                        <InputError message={errors.slug} />
                                    </div>

                                    <Button
                                        type="submit"
                                        className="self-start"
                                        disabled={processing}
                                    >
                                        {processing && <Spinner />}
                                        {t('provider.edit.submit')}
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

// Kit texts in English, like the other breadcrumbs, until the kit is translated (issue #54).
ProviderEditPage.layout = {
    breadcrumbs: [
        {
            title: 'Dashboard',
            href: dashboard(),
        },
        {
            title: 'Edit profile',
            href: edit(),
        },
    ],
};
