import { useCallback, useEffect, useState } from 'react';
import { useTranslation } from '@/hooks/use-translation';
import type { FreeSlot } from '@/types';

type Failure = { kind: 'invalid'; message: string } | { kind: 'network' };

export type UseFreeSlotsReturn = {
    slots: FreeSlot[];
    loading: boolean;
    error: string | null;
    reload: () => void;
};

// The free slots of a day, read from a slots endpoint: the full address, query included, built by
// the caller with Wayfinder (the public route or the provider's own); null when no day is chosen
// yet. A string, so that a new address with the same text does not start a new request.
export function useFreeSlots(url: string | null): UseFreeSlotsReturn {
    const { t } = useTranslation();
    const [slots, setSlots] = useState<FreeSlot[]>([]);
    const [loading, setLoading] = useState(false);
    const [failure, setFailure] = useState<Failure | null>(null);
    // Changing this number asks for the same slots again (after a time was taken).
    const [reloadCount, setReloadCount] = useState(0);

    useEffect(() => {
        setSlots([]);
        setFailure(null);

        if (url === null) {
            setLoading(false);

            return;
        }

        // When the address changes, React runs the cleanup below first: the old request is
        // aborted, and the checks on "aborted" stop its answer from overwriting the new one.
        const controller = new AbortController();

        setLoading(true);

        fetch(url, {
            headers: { Accept: 'application/json' },
            signal: controller.signal,
        })
            .then(async (response) => {
                const body = await response.json();

                if (controller.signal.aborted) {
                    return;
                }

                if (response.ok) {
                    setSlots(body.slots as FreeSlot[]);
                } else if (response.status === 422) {
                    const messages = Object.values(
                        (body.errors ?? {}) as Record<string, string[]>,
                    );
                    setFailure(
                        messages.length > 0
                            ? { kind: 'invalid', message: messages[0][0] }
                            : { kind: 'network' },
                    );
                } else {
                    setFailure({ kind: 'network' });
                }
            })
            .catch(() => {
                if (!controller.signal.aborted) {
                    setFailure({ kind: 'network' });
                }
            })
            .finally(() => {
                if (!controller.signal.aborted) {
                    setLoading(false);
                }
            });

        return () => controller.abort();
    }, [url, reloadCount]);

    const reload = useCallback(() => setReloadCount((count) => count + 1), []);

    // Translated here, not inside the effect: t changes at every render and would start new requests.
    const error =
        failure === null
            ? null
            : failure.kind === 'invalid'
              ? failure.message
              : t('booking.form.slots_error');

    return { slots, loading, error, reload };
}
