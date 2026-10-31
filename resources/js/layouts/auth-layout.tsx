import { useTranslation } from '@/hooks/use-translation';
import AuthLayoutTemplate from '@/layouts/auth/auth-simple-layout';

// The pages give translation keys, not texts: their "layout" is a static object, outside the
// components, so the texts are translated here.
export default function AuthLayout({
    titleKey = '',
    descriptionKey = '',
    children,
}: {
    titleKey?: string;
    descriptionKey?: string;
    children: React.ReactNode;
}) {
    const { t } = useTranslation();

    return (
        <AuthLayoutTemplate
            title={titleKey === '' ? '' : t(titleKey)}
            description={descriptionKey === '' ? '' : t(descriptionKey)}
        >
            {children}
        </AuthLayoutTemplate>
    );
}
