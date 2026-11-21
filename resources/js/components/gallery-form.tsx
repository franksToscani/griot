import { Form, router } from '@inertiajs/react';
import { useRef, useState } from 'react';
import type { ChangeEvent, FormEvent } from 'react';
import { toast } from 'sonner';
import { CARD_TITLE_FOCUS } from '@/components/add-panel';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
} from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { silenceFlashToast } from '@/hooks/use-flash-toast';
import { useTranslation } from '@/hooks/use-translation';
import type { TranslateFn } from '@/hooks/use-translation';
import { errorId, fieldA11y, focusFirstInvalid } from '@/lib/form-a11y';
import {
    fallbackSrc,
    galleryState,
    photoAlt,
    srcSet,
    THUMBNAIL_GRID,
    THUMBNAIL_SIZES,
} from '@/lib/gallery';
import { resizeImage } from '@/lib/resize-image';
import {
    addFiles,
    freeSlots,
    refusedText,
    removeItem,
    retryItem,
    runQueue,
    summaryText,
    updateItem,
    uploadError,
} from '@/lib/upload-queue';
import type { SendResult, UploadItem } from '@/lib/upload-queue';
// The gallery's own "store" and "destroy", not the profile ones of '@/routes/provider'.
import {
    destroy as destroyPhoto,
    store as storePhoto,
} from '@/routes/provider/photos';
import type { GalleryLimits, ProviderPhotoEdit } from '@/types';

// The title of the card takes the focus after a removal (tabIndex={-1}): the button the focus was
// on is gone with its photo. The same at the end of a queue of uploads. The result itself is the
// toast, read by Sonner's live region. No ring: it is reached by script only (CARD_TITLE_FOCUS).
export const TITLE_ID = 'gallery-title';

function focusTitle(): void {
    requestAnimationFrame(() => {
        document.getElementById(TITLE_ID)?.focus();
    });
}

// The card "Foto del tuo lavoro" of the edit page (issue #143, docs/gallery.md): the photos in
// their order, each with its own removal, and the form to add one, with the consent.
export function GalleryForm({
    photos,
    limits,
}: {
    photos: ProviderPhotoEdit[];
    limits: GalleryLimits;
}) {
    const { t } = useTranslation();
    const total = photos.length;
    const state = galleryState(total, limits.max_photos);

    return (
        <Card className="max-w-2xl">
            <CardHeader>
                <h2
                    id={TITLE_ID}
                    tabIndex={-1}
                    className={`text-xl leading-none font-semibold ${CARD_TITLE_FOCUS}`}
                >
                    {t('provider.gallery.title')}
                </h2>
                <CardDescription>
                    {t('provider.gallery.description', {
                        max: limits.max_photos,
                    })}
                </CardDescription>
            </CardHeader>
            <CardContent className="flex flex-col gap-6">
                <p className="text-sm font-medium">
                    {t('provider.gallery.count', {
                        count: total,
                        max: limits.max_photos,
                    })}
                </p>

                {state === 'empty' ? (
                    <p className="text-sm text-muted-foreground">
                        {t('provider.gallery.empty')}
                    </p>
                ) : (
                    // One column on phones, three from md; two from xl, where the card is the 24rem
                    // column of the profile page (THUMBNAIL_GRID, with its sizes in lib/gallery.ts).
                    // key = id, so that each row keeps its own dialog.
                    <ul className={THUMBNAIL_GRID}>
                        {photos.map((photo, index) => (
                            <li key={photo.id} className="flex flex-col gap-2">
                                <PhotoItem
                                    photo={photo}
                                    number={index + 1}
                                    total={total}
                                />
                            </li>
                        ))}
                    </ul>
                )}

                {/* Always mounted: a queue that fills the gallery still ends here (its summary and
                    the focus). With the gallery full and nothing in the queue, the message instead
                    of the form. */}
                <UploadForm
                    minSide={limits.min_side}
                    max={limits.max_photos}
                    existing={total}
                />
            </CardContent>
        </Card>
    );
}

function PhotoItem({
    photo,
    number,
    total,
}: {
    photo: ProviderPhotoEdit;
    number: number;
    total: number;
}) {
    const { t } = useTranslation();

    return (
        <>
            {/* width and height: the space is kept while the photo loads. A square crop for the
                thumbnails only: the photo itself is not cut. Every size in srcset: the browser
                takes the one the thumbnail needs on that screen. */}
            <img
                src={fallbackSrc(photo.variants)}
                srcSet={srcSet(photo.variants)}
                sizes={THUMBNAIL_SIZES}
                width={photo.width}
                height={photo.height}
                alt={photoAlt(t, photo.alt_text, number, total)}
                loading="lazy"
                decoding="async"
                className="aspect-square w-full rounded-md bg-muted object-cover"
            />
            {/* The same words as the alt above: shown for the provider, not read twice. */}
            {photo.alt_text && (
                <p
                    aria-hidden="true"
                    className="text-sm break-words text-muted-foreground"
                >
                    {photo.alt_text}
                </p>
            )}
            <RemovePhotoDialog
                photoId={photo.id}
                number={number}
                total={total}
            />
        </>
    );
}

// Removing asks for a confirmation in the kit's Dialog (focus kept inside, Esc, focus back on
// "Togli" when cancelled). After a removal the focus goes to the title of the card. A refusal (the
// request limit) is read inside the dialog, which stays open.
function RemovePhotoDialog({
    photoId,
    number,
    total,
}: {
    photoId: number;
    number: number;
    total: number;
}) {
    const { t } = useTranslation();
    const [open, setOpen] = useState(false);
    const removed = useRef(false);

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                {/* "Togli la foto 2 di 6" for screen readers, "Togli" on the screen: the name
                    starts with the visible text, as voice control expects. */}
                <Button variant="outline" size="sm" className="self-start">
                    {t('provider.gallery.remove')}
                    <span className="sr-only">
                        {' '}
                        {t('provider.gallery.remove_which', { number, total })}
                    </span>
                </Button>
            </DialogTrigger>
            <DialogContent
                onCloseAutoFocus={(event) => {
                    // After a removal the trigger is gone: the focus goes to the title instead.
                    if (removed.current) {
                        event.preventDefault();
                    }
                }}
            >
                <DialogHeader>
                    <DialogTitle>
                        {t('provider.gallery.remove_title', { number, total })}
                    </DialogTitle>
                    <DialogDescription>
                        {t('provider.gallery.remove_description')}
                    </DialogDescription>
                </DialogHeader>
                <Form
                    {...destroyPhoto.form(photoId)}
                    options={{ preserveScroll: true }}
                    onSuccess={() => {
                        removed.current = true;
                        setOpen(false);
                        focusTitle();
                    }}
                >
                    {({ processing, errors }) => (
                        <>
                            <InputError message={errors.photo} announce />
                            <DialogFooter className="gap-2">
                                <DialogClose asChild>
                                    <Button type="button" variant="secondary">
                                        {t('provider.gallery.remove_cancel')}
                                    </Button>
                                </DialogClose>
                                <Button
                                    type="submit"
                                    variant="destructive"
                                    disabled={processing}
                                >
                                    {processing && <Spinner />}
                                    {t('provider.gallery.remove_confirm')}
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}

// One photo of the queue: made smaller in the browser (resize-image.ts, the original if it cannot
// be), then sent to the route that already takes one photo, with its description and the consent of
// the group, so that consent_at is written on every row. The server does not change. preserveState:
// the queue stays on the page while the gallery gets each new photo.
function sendPhoto(
    item: UploadItem<File>,
    onProgress: (percentage: number) => void,
    fallback: string,
): Promise<SendResult> {
    return resizeImage(item.file).then(
        (photo) =>
            new Promise<SendResult>((resolve) => {
                let result: SendResult = { ok: false, error: fallback };

                router.post(
                    storePhoto.url(),
                    { photo, alt_text: item.alt, consent: true },
                    {
                        preserveScroll: true,
                        preserveState: true,
                        forceFormData: true,
                        onProgress: (event) =>
                            onProgress(event?.percentage ?? 0),
                        onSuccess: () => {
                            result = { ok: true };
                        },
                        onError: (errors) => {
                            result = {
                                ok: false,
                                error: uploadError(errors, fallback),
                            };
                        },
                        onFinish: () => resolve(result),
                    },
                );
            }),
    );
}

// What a file of the list says of itself.
function stateText(t: TranslateFn, item: UploadItem<File>): string {
    if (item.state === 'error') {
        return t('provider.gallery.queue.state_error', {
            reason: item.error ?? '',
        });
    }

    return t(`provider.gallery.queue.state_${item.state}`);
}

// The upload form (#53, docs/gallery.md): several photos at once, each with its own optional
// description, one consent for the group, sent one after the other (lib/upload-queue.ts). The
// files over the free places are refused before anything is sent. At the end the focus goes to
// the title of the card, with one summary as a toast and on the status line.
function UploadForm({
    minSide,
    max,
    existing,
}: {
    minSide: number;
    max: number;
    existing: number;
}) {
    const { t } = useTranslation();
    const lastId = useRef(0);
    const [items, setItems] = useState<UploadItem<File>[]>([]);
    const [consent, setConsent] = useState(false);
    const [consentError, setConsentError] = useState<string | null>(null);
    const [refused, setRefused] = useState<string | null>(null);
    const [running, setRunning] = useState(false);
    const [status, setStatus] = useState('');
    const free = freeSlots(max, existing, items);
    const waiting = items.some((item) => item.state === 'queued');

    const choose = (event: ChangeEvent<HTMLInputElement>) => {
        const chosen = Array.from(event.target.files ?? []);
        const result = addFiles(items, chosen, free, () => ++lastId.current);

        setItems(result.items);
        setRefused(
            result.refused.length > 0
                ? refusedText(t, result.refused.length, max)
                : null,
        );
        // Empty again: the list holds the choice, and the same file can be chosen once more.
        event.target.value = '';
    };

    const run = async (list: UploadItem<File>[]) => {
        if (running) {
            return;
        }

        if (!consent) {
            setConsentError(t('provider.gallery.queue.consent_required'));
            focusFirstInvalid('gallery-form');

            return;
        }

        setConsentError(null);
        setRunning(true);
        setStatus(t('provider.gallery.uploading'));
        // Each photo saved flashes its own toast: the queue says it once, at the end.
        silenceFlashToast(t('provider.photos.saved'));

        let summary = { added: 0, failed: 0 };

        try {
            summary = await runQueue(
                list,
                (item, onProgress) =>
                    sendPhoto(
                        item,
                        onProgress,
                        t('provider.validation.photo_not_saved'),
                    ),
                setItems,
            );
        } finally {
            silenceFlashToast(null);
            setRunning(false);
        }

        const text = summaryText(t, summary);
        setStatus(text ?? '');

        if (text !== null) {
            if (summary.failed > 0) {
                toast.error(text);
            } else {
                toast.success(text);
            }
        }

        focusTitle();
    };

    const submit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        void run(items);
    };

    const retry = (id: number) => {
        const list = retryItem(items, id);
        setItems(list);
        void run(list);
    };

    if (free === 0 && items.length === 0 && !running) {
        return (
            <div className="flex flex-col gap-2">
                <p className="text-sm text-muted-foreground">
                    {t('provider.gallery.full', { max })}
                </p>
                <p role="status" className="text-sm text-muted-foreground">
                    {status}
                </p>
            </div>
        );
    }

    return (
        <form
            id="gallery-form"
            onSubmit={submit}
            className="flex flex-col gap-4"
            noValidate
        >
            <div className="grid gap-2">
                <Label htmlFor="gallery-photo">
                    {t('provider.gallery.photo_label')}
                </Label>
                {/* accept: an iPhone turns a HEIC photo into a JPEG when it is chosen. */}
                <Input
                    id="gallery-photo"
                    type="file"
                    multiple
                    accept="image/jpeg,image/png,image/webp"
                    disabled={running || free === 0}
                    onChange={choose}
                    {...fieldA11y(
                        'gallery-photo',
                        refused ?? undefined,
                        'gallery-photo-help',
                    )}
                />
                <p
                    id="gallery-photo-help"
                    className="text-sm text-muted-foreground"
                >
                    {t('provider.gallery.photo_help', { min: minSide })}
                </p>
                <InputError
                    id={errorId('gallery-photo')}
                    message={refused ?? undefined}
                />
            </div>

            {items.length > 0 && (
                <>
                    <p
                        id="gallery-alt-help"
                        className="text-sm text-muted-foreground"
                    >
                        {t('provider.gallery.alt_help')}
                    </p>
                    <ul className="flex flex-col gap-4">
                        {items.map((item) => (
                            <li
                                key={item.id}
                                className="flex flex-col gap-2 rounded-md border p-3"
                            >
                                {/* The file's name, as the native label of its progress bar. */}
                                <label
                                    htmlFor={`upload-${item.id}-progress`}
                                    className="text-sm font-medium break-all"
                                >
                                    {item.file.name}
                                </label>
                                <div className="grid gap-2">
                                    <Label htmlFor={`upload-${item.id}-alt`}>
                                        {t('provider.gallery.queue.alt_label', {
                                            name: item.file.name,
                                        })}
                                    </Label>
                                    <Input
                                        id={`upload-${item.id}-alt`}
                                        maxLength={150}
                                        autoComplete="off"
                                        value={item.alt}
                                        disabled={running}
                                        onChange={(event) =>
                                            setItems(
                                                updateItem(items, item.id, {
                                                    alt: event.target.value,
                                                }),
                                            )
                                        }
                                        aria-describedby="gallery-alt-help"
                                    />
                                </div>
                                <p className="text-sm">{stateText(t, item)}</p>
                                {/* Native: the share sent of the file, named by the file. */}
                                <progress
                                    id={`upload-${item.id}-progress`}
                                    value={item.progress}
                                    max={100}
                                    className="h-2 w-full accent-primary"
                                />
                                {item.state !== 'uploading' && (
                                    <div className="flex flex-wrap gap-2">
                                        {item.state === 'error' && (
                                            <Button
                                                type="button"
                                                variant="outline"
                                                size="sm"
                                                disabled={running}
                                                onClick={() => retry(item.id)}
                                            >
                                                {t(
                                                    'provider.gallery.queue.retry',
                                                )}
                                            </Button>
                                        )}
                                        <Button
                                            type="button"
                                            variant="outline"
                                            size="sm"
                                            disabled={running}
                                            onClick={() =>
                                                setItems(
                                                    removeItem(items, item.id),
                                                )
                                            }
                                        >
                                            {t('provider.gallery.queue.remove')}
                                        </Button>
                                    </div>
                                )}
                            </li>
                        ))}
                    </ul>
                </>
            )}

            {/* One consent for the whole group, sent with every photo. */}
            <div className="grid gap-2">
                {/* A 44px row on phones, as the box's invisible area (ui/checkbox). */}
                <div className="flex items-start gap-3 max-md:min-h-11">
                    <Checkbox
                        id="gallery-consent"
                        checked={consent}
                        disabled={running}
                        onCheckedChange={(checked) => {
                            setConsent(checked === true);
                            setConsentError(null);
                        }}
                        className="mt-0.5"
                        {...fieldA11y(
                            'gallery-consent',
                            consentError ?? undefined,
                        )}
                    />
                    <Label htmlFor="gallery-consent" className="leading-snug">
                        {t('provider.photos.consent')}
                    </Label>
                </div>
                <InputError
                    id={errorId('gallery-consent')}
                    message={consentError ?? undefined}
                />
            </div>

            <Button
                type="submit"
                className="self-start"
                disabled={!waiting || running}
            >
                {/* Hidden: the status line below says it, once. */}
                {running && <Spinner aria-hidden="true" />}
                {t('provider.gallery.submit')}
            </Button>

            {/* Always on the page, so that the change is read when it happens. */}
            <p role="status" className="text-sm text-muted-foreground">
                {status}
            </p>
        </form>
    );
}
