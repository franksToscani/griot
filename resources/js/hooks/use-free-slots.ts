import { useCallback, useEffect, useState } from 'react';
import { useTranslation } from '@/hooks/use-translation';
import { slots as slotsRoute } from '@/routes/providers';
import type { FreeSlot } from '@/types';

type Params = {
    providerSlug: string;
    serviceId: number;
    optionIds: number[];
    // "YYYY-MM-DD", or empty when no day is chosen yet.
    day: string;
};

type Failure = { kind: 'invalid'; message: string } | { kind: 'network' };

export type UseFreeSlotsReturn = {
    slots: FreeSlot[];
    loading: boolean;
    error: string | null;
    reload: () => void;
};

// The free slots of a day, read from the slots endpoint.
export function useFreeSlots({
    providerSlug,
    serviceId,
    optionIds,
    day,
}: Params): UseFreeSlotsReturn {
    const { t } = useTranslation();
    const [slots, setSlots] = useState<FreeSlot[]>([]);
    const [loading, setLoading] = useState(false);
    const [failure, setFailure] = useState<Failure | null>(null);
    // Changing this number asks for the same slots again (after a time was taken).
    const [reloadCount, setReloadCount] = useState(0);

    // A string, so that a new array with the same ids does not start a new request.
    const optionsKey = optionIds.join(',');

    useEffect(() => {
        setSlots([]);
        setFailure(null);

        if (day === '') {
            setLoading(false);

            return;
        }

        // When day or options change, React runs the cleanup below first: the old request is
        // aborted, and the checks on "aborted" stop its answer from overwriting the new one.
        const controller = new AbortController();
        const ids = optionsKey === '' ? [] : optionsKey.split(',').map(Number);
        // Wayfinder writes the array as options[]=1&options[]=2.
        const url = slotsRoute.url(providerSlug, {
            query: { service: serviceId, date: day, options: ids },
        });

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
    }, [providerSlug, serviceId, optionsKey, day, reloadCount]);

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
