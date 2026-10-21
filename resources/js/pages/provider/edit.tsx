import { Form, Head } from '@inertiajs/react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import { ProviderAvatar } from '@/components/provider-avatar';
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
// The photo's own "store", not the profile one of '@/routes/provider'.
import { store as storeAvatar } from '@/routes/provider/avatar';
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
            <div className="flex flex-col gap-6 p-4">
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

                <AvatarForm
                    name={provider.name}
                    avatarUrl={provider.avatar_url}
                />
            </div>
        </>
    );
}

// The photo, in a form of its own: a separate request from the profile, so pressing Enter in a
// profile field never sends it, and its ids ("avatar") are not used by the profile form.
function AvatarForm({
    name,
    avatarUrl,
}: {
    name: string;
    avatarUrl: string | null;
}) {
    const { t } = useTranslation();
    // Whether a file is chosen: the input is not controlled, so React needs to be told.
    const [hasFile, setHasFile] = useState(false);

    return (
        <Card className="max-w-2xl">
            <CardHeader>
                <h2 className="text-xl leading-none font-semibold">
                    {t('provider.avatar.title')}
                </h2>
            </CardHeader>
            <CardContent>
                {/* resetOnSuccess empties the file input after a successful upload, and onSuccess
                    turns the button off again: no "chosen" state left with an empty input. */}
                <Form
                    {...storeAvatar.form()}
                    options={{ preserveScroll: true }}
                    encType="multipart/form-data"
                    resetOnSuccess
                    onSuccess={() => setHasFile(false)}
                    className="flex flex-col gap-4"
                >
                    {({ processing, errors }) => (
                        <>
                            <ProviderAvatar
                                name={name}
                                avatarUrl={avatarUrl}
                                className="size-24 text-2xl"
                            />
                            <div className="grid gap-2">
                                <Label htmlFor="avatar">
                                    {t('provider.avatar.label')}
                                </Label>
                                <Input
                                    id="avatar"
                                    name="avatar"
                                    type="file"
                                    accept="image/jpeg,image/png,image/webp"
                                    aria-describedby="avatar-help"
                                    onChange={(event) =>
                                        setHasFile(
                                            (event.target.files?.length ?? 0) >
                                                0,
                                        )
                                    }
                                />
                                <p
                                    id="avatar-help"
                                    className="text-sm text-muted-foreground"
                                >
                                    {t('provider.avatar.help')}
                                </p>
                                <InputError message={errors.avatar} />
                            </div>
                            <Button
                                type="submit"
                                className="self-start"
                                disabled={!hasFile || processing}
                            >
                                {processing && <Spinner />}
                                {t('provider.avatar.submit')}
                            </Button>
                        </>
                    )}
                </Form>
            </CardContent>
        </Card>
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
