import { useFlashToast } from '@/hooks/use-flash-toast';
import { useAppearance } from '@/hooks/use-appearance';
import { useNotificationsLabel } from '@/lib/document-language';
import { Toaster as Sonner, type ToasterProps } from 'sonner';

function Toaster({ ...props }: ToasterProps) {
    const { appearance } = useAppearance();
    // Translated: the Toaster is outside the Inertia app, so not through useTranslation().
    const notificationsLabel = useNotificationsLabel();

    useFlashToast();

    return (
        <Sonner
            theme={appearance}
            className="toaster group"
            // Top centre on every page (#53): at the bottom right a toast covered the "add" form
            // that stays in view in the provider's area, and the buttons of the home page.
            position="top-center"
            containerAriaLabel={notificationsLabel}
            style={
                {
                    '--normal-bg': 'var(--popover)',
                    '--normal-text': 'var(--popover-foreground)',
                    '--normal-border': 'var(--border)',
                } as React.CSSProperties
            }
            {...props}
        />
    );
}

export { Toaster };
