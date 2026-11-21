import { router } from '@inertiajs/react';
import { useEffect } from 'react';
import { toast } from 'sonner';
import type { FlashToast } from '@/types/ui';

// One message left out while the gallery sends a queue of photos (components/gallery-form.tsx,
// lib/upload-queue.ts): each photo saved flashes "Foto aggiunta.", and the queue shows one summary
// at the end instead. Only that message, so the other toasts (a profile saved meanwhile) still show.
let silenced: string | null = null;

export function silenceFlashToast(message: string | null): void {
    silenced = message;
}

export function useFlashToast(): void {
    useEffect(() => {
        return router.on('flash', (event) => {
            const flash = (event as CustomEvent).detail?.flash;
            const data = flash?.toast as FlashToast | undefined;

            if (!data || data.message === silenced) {
                return;
            }

            toast[data.type](data.message);
        });
    }, []);
}
