import { createInertiaApp } from '@inertiajs/react';
import { Toaster } from '@/components/ui/sonner';
import { TooltipProvider } from '@/components/ui/tooltip';
import { initializeTheme } from '@/hooks/use-appearance';
import AppLayout from '@/layouts/app-layout';
import AuthLayout from '@/layouts/auth-layout';
import PublicLayout, { PublicBookingLayout } from '@/layouts/public-layout';
import SettingsLayout from '@/layouts/settings/layout';
import { syncDocumentLanguage } from '@/lib/document-language';

const appName = import.meta.env.VITE_APP_NAME || 'Griot';

// Before the app starts: <html lang> and the toasts' label follow every page, the first included.
syncDocumentLanguage();

void createInertiaApp({
    title: (title) => (title ? `${title} - ${appName}` : appName),
    layout: (name) => {
        switch (true) {
            // The customer's own booking: the public layout without "Accedi".
            case name === 'bookings/show':
                return PublicBookingLayout;
            case name.startsWith('auth/'):
                return AuthLayout;
            case name.startsWith('providers/'):
            case name.startsWith('bookings/'):
            case name === 'privacy':
            case name === 'terms':
            case name === 'home':
                return PublicLayout;
            case name.startsWith('settings/'):
                return [AppLayout, SettingsLayout];
            default:
                return AppLayout;
        }
    },
    strictMode: true,
    withApp(app) {
        return (
            <TooltipProvider delayDuration={0}>
                {app}
                <Toaster />
            </TooltipProvider>
        );
    },
    progress: {
        // The brown of the buttons (--primary of the light theme): the bar is drawn by Inertia,
        // outside the theme's classes.
        color: '#54220F',
    },
});

// This will set light / dark mode on load...
initializeTheme();
