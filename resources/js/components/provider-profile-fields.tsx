import InputError from '@/components/input-error';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { useTranslation } from '@/hooks/use-translation';
import type { ProviderProfileValues } from '@/types';

type Props = {
    // The starting values: none on the creation page, the saved ones on the edit page.
    defaults?: ProviderProfileValues;
    errors: Partial<Record<keyof ProviderProfileValues, string>>;
};

// The five profile fields of a provider, shared by the creation and the edit page. They are not
// controlled: after a validation error the values written by the provider stay in the fields.
export function ProviderProfileFields({ defaults, errors }: Props) {
    const { t } = useTranslation();

    return (
        <>
            <div className="grid gap-2">
                <Label htmlFor="name">{t('provider.setup.form.name')}</Label>
                <Input
                    id="name"
                    name="name"
                    required
                    maxLength={150}
                    autoComplete="organization"
                    defaultValue={defaults?.name}
                />
                <InputError message={errors.name} />
            </div>

            <div className="grid gap-2">
                <Label htmlFor="city">{t('provider.setup.form.city')}</Label>
                <Input
                    id="city"
                    name="city"
                    required
                    maxLength={100}
                    autoComplete="address-level2"
                    defaultValue={defaults?.city}
                />
                <InputError message={errors.city} />
            </div>

            <div className="grid gap-2">
                <Label htmlFor="bio">{t('provider.setup.form.bio')}</Label>
                <Textarea
                    id="bio"
                    name="bio"
                    maxLength={1000}
                    autoComplete="off"
                    defaultValue={defaults?.bio ?? undefined}
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
                    defaultValue={defaults?.whatsapp ?? undefined}
                />
                <p id="whatsapp-help" className="text-sm text-muted-foreground">
                    {t('provider.setup.form.whatsapp_help')}
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
                    defaultValue={defaults?.instagram ?? undefined}
                />
                <p
                    id="instagram-help"
                    className="text-sm text-muted-foreground"
                >
                    {t('provider.setup.form.instagram_help')}
                </p>
                <InputError message={errors.instagram} />
            </div>
        </>
    );
}
