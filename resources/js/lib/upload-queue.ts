// The queue of photos the gallery sends (#53, docs/gallery.md): the files chosen, in the order
// they were chosen, sent one at a time to the route that already takes one photo. Never two at
// once: the server has half a CPU and 512 MB, and each photo is decoded there. Nothing is saved:
// the queue lives in the page and is gone with it. Apart from React so that it can be tested
// with Node, as lib/gallery.ts.
import type { TranslateFn } from '@/hooks/use-translation';

// "queued": waiting for its turn. "uploading": being made smaller and sent. "done": saved, it
// leaves the list (it is in the gallery now). "error": refused, with the server's reason, until
// it is sent again or taken away.
export type UploadState = 'queued' | 'uploading' | 'done' | 'error';

export type UploadItem<F> = {
    id: number;
    file: F;
    alt: string;
    state: UploadState;
    // 0-100, what the browser has sent of the file.
    progress: number;
    error: string | null;
};

// What sending one photo gave: saved, or refused with a message for that file.
export type SendResult = { ok: true } | { ok: false; error: string };

// The photos still to go in the gallery: the queue's own count, so that the files over the
// limit are refused before anything is sent. Saved photos leave the list and are counted in
// "existing" from then on.
export function freeSlots<F>(
    max: number,
    existing: number,
    items: UploadItem<F>[],
): number {
    const waiting = items.filter((item) => item.state !== 'done').length;

    return Math.max(0, max - existing - waiting);
}

// The files chosen, added at the end of the list up to the free places; the others are refused
// here, never sent. "nextId" gives each file its own id, also when two have the same name.
export function addFiles<F>(
    items: UploadItem<F>[],
    files: F[],
    free: number,
    nextId: () => number,
): { items: UploadItem<F>[]; refused: F[] } {
    const accepted = files.slice(0, Math.max(0, free));

    return {
        items: [
            ...items,
            ...accepted.map((file) => ({
                id: nextId(),
                file,
                alt: '',
                state: 'queued' as const,
                progress: 0,
                error: null,
            })),
        ],
        refused: files.slice(accepted.length),
    };
}

export function updateItem<F>(
    items: UploadItem<F>[],
    id: number,
    change: Partial<Omit<UploadItem<F>, 'id' | 'file'>>,
): UploadItem<F>[] {
    return items.map((item) =>
        item.id === id ? { ...item, ...change } : item,
    );
}

// Taken away before it is sent (or after an error): it does not go.
export function removeItem<F>(
    items: UploadItem<F>[],
    id: number,
): UploadItem<F>[] {
    return items.filter((item) => item.id !== id);
}

// "Riprova": back in the queue, without its error.
export function retryItem<F>(
    items: UploadItem<F>[],
    id: number,
): UploadItem<F>[] {
    return items.map((item) =>
        item.id === id && item.state === 'error'
            ? { ...item, state: 'queued', progress: 0, error: null }
            : item,
    );
}

// The next file to send: the first waiting, in the order of the list.
export function nextQueued<F>(
    items: UploadItem<F>[],
): UploadItem<F> | undefined {
    return items.find((item) => item.state === 'queued');
}

export type QueueSummary = { added: number; failed: number };

// Sends the waiting files one after the other: the next only when the one before has finished,
// saved or refused. A saved file leaves the list; a refused one stays with its reason. onChange
// gets the list after every step, for the page.
export async function runQueue<F>(
    items: UploadItem<F>[],
    send: (
        item: UploadItem<F>,
        onProgress: (percentage: number) => void,
    ) => Promise<SendResult>,
    onChange: (items: UploadItem<F>[]) => void,
): Promise<QueueSummary> {
    let current = items;
    const summary: QueueSummary = { added: 0, failed: 0 };
    const change = (next: UploadItem<F>[]) => {
        current = next;
        onChange(current);
    };

    for (
        let item = nextQueued(current);
        item !== undefined;
        item = nextQueued(current)
    ) {
        const id = item.id;
        change(updateItem(current, id, { state: 'uploading', progress: 0 }));

        const result = await send(item, (percentage) =>
            change(updateItem(current, id, { progress: percentage })),
        );

        if (result.ok) {
            summary.added++;
            change(removeItem(current, id));
        } else {
            summary.failed++;
            change(
                updateItem(current, id, {
                    state: 'error',
                    progress: 0,
                    error: result.error,
                }),
            );
        }
    }

    return summary;
}

// The reason for one file, from the server's errors of that request: the photo's first (too
// heavy, too small, not a photo, gallery full, too many requests), then the description's, then
// the consent's. The server writes them in the language of the page.
export function uploadError(
    errors: Record<string, string | undefined>,
    fallback: string,
): string {
    return errors.photo ?? errors.alt_text ?? errors.consent ?? fallback;
}

// "7 foto aggiunte, 2 foto non caricate.": the summary at the end of the queue, with the plural
// of the project (a key "_one" for 1). Null when nothing was sent.
export function summaryText(
    t: TranslateFn,
    summary: QueueSummary,
): string | null {
    const added =
        summary.added === 1
            ? t('provider.gallery.queue.added_one')
            : t('provider.gallery.queue.added', { count: summary.added });
    const failed =
        summary.failed === 1
            ? t('provider.gallery.queue.failed_one')
            : t('provider.gallery.queue.failed', { count: summary.failed });

    if (summary.added > 0 && summary.failed > 0) {
        return t('provider.gallery.queue.summary_both', { added, failed });
    }

    if (summary.added > 0) {
        return t('provider.gallery.queue.summary', { part: added });
    }

    return summary.failed > 0
        ? t('provider.gallery.queue.summary', { part: failed })
        : null;
}

// The refusal of the files over the limit, said once for the whole choice.
export function refusedText(
    t: TranslateFn,
    count: number,
    max: number,
): string {
    return count === 1
        ? t('provider.gallery.queue.refused_one', { max })
        : t('provider.gallery.queue.refused', { count, max });
}
