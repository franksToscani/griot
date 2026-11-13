import type { Auth } from '@/types/auth';

declare module 'react' {
    interface InputHTMLAttributes<T> {
        passwordrules?: string;
    }
}

declare module '@inertiajs/core' {
    export interface InertiaConfig {
        sharedPageProps: {
            name: string;
            auth: Auth;
            sidebarOpen: boolean;
            locale: string;
            // The languages of the selector, from config/app.php (available_locales).
            locales: { code: string; name: string }[];
            translations: Record<string, string>;
            // The site's contacts for the footer (config/contact.php): null when not configured.
            // phone_url: tel:+<digits>, from the same number as WhatsApp.
            contact: {
                email: string | null;
                whatsapp_url: string | null;
                phone_url: string | null;
            };
            [key: string]: unknown;
        };
    }
}
