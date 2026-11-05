import { Form, useForm } from '@inertiajs/react';
import { useRef, useState } from 'react';
import type { FormEvent } from 'react';
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
import { useTranslation } from '@/hooks/use-translation';
import { errorId, fieldA11y, focusFirstInvalid } from '@/lib/form-a11y';
import { galleryState, photoAlt } from '@/lib/gallery';
import { resizeImage } from '@/lib/resize-image';
// The gallery's own "store" and "destroy", not the profile ones of '@/routes/provider'.
import {
    destroy as destroyPhoto,
    store as storePhoto,
} from '@/routes/provider/photos';
import type { GalleryLimits, ProviderPhotoEdit } from '@/types';

// The title of the card takes the focus after a removal (tabIndex={-1}): the button the focus was
// on is gone with its photo. The result itself is the toast, read by Sonner's live region.
const TITLE_ID = 'gallery-title';

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
                    className="text-xl leading-none font-semibold"
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
                    // One column on phones; key = id, so that each row keeps its own dialog.
                    <ul className="grid grid-cols-1 gap-4 md:grid-cols-3">
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

                {state === 'full' ? (
                    <p className="text-sm text-muted-foreground">
                        {t('provider.gallery.full', {
                            max: limits.max_photos,
                        })}
                    </p>
                ) : (
                    <UploadForm minSide={limits.min_side} />
                )}
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
                thumbnails only: the photo itself is not cut. */}
            <img
                src={photo.url}
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

type UploadData = {
    photo: File | null;
    alt_text: string;
    consent: boolean;
};

// The upload form. The photo is made smaller in the browser first (resize-image.ts), only when the
// form is sent: nothing touches window or a canvas while rendering. If the browser cannot read
// it, the original goes and the server decides. The button needs a file, not the consent: an
// unticked box gets the server's error next to it.
function UploadForm({ minSide }: { minSide: number }) {
    const { t } = useTranslation();
    const fileInput = useRef<HTMLInputElement>(null);
    // From the click to the start of the request: the resize, which can take a moment.
    const [preparing, setPreparing] = useState(false);
    const form = useForm<UploadData>({
        photo: null,
        alt_text: '',
        consent: false,
    });
    const busy = preparing || form.processing;

    const submit = async (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        const original = form.data.photo;

        if (original === null || busy) {
            return;
        }

        setPreparing(true);
        const photo = await resizeImage(original);

        form.transform((data) => ({ ...data, photo }));
        form.post(storePhoto.url(), {
            preserveScroll: true,
            forceFormData: true,
            onSuccess: () => {
                // Empty again: file, description and box. The new photo is in the list.
                form.reset();
                if (fileInput.current !== null) {
                    fileInput.current.value = '';
                }
            },
            onError: () => focusFirstInvalid('gallery-form'),
            onFinish: () => setPreparing(false),
        });
    };

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
                    ref={fileInput}
                    id="gallery-photo"
                    type="file"
                    accept="image/jpeg,image/png,image/webp"
                    onChange={(event) =>
                        form.setData('photo', event.target.files?.[0] ?? null)
                    }
                    {...fieldA11y(
                        'gallery-photo',
                        form.errors.photo,
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
                    message={form.errors.photo}
                />
            </div>

            <div className="grid gap-2">
                <Label htmlFor="gallery-alt">
                    {t('provider.gallery.alt_label')}
                </Label>
                <Input
                    id="gallery-alt"
                    maxLength={150}
                    autoComplete="off"
                    value={form.data.alt_text}
                    onChange={(event) =>
                        form.setData('alt_text', event.target.value)
                    }
                    {...fieldA11y(
                        'gallery-alt',
                        form.errors.alt_text,
                        'gallery-alt-help',
                    )}
                />
                <p
                    id="gallery-alt-help"
                    className="text-sm text-muted-foreground"
                >
                    {t('provider.gallery.alt_help')}
                </p>
                <InputError
                    id={errorId('gallery-alt')}
                    message={form.errors.alt_text}
                />
            </div>

            <div className="grid gap-2">
                {/* A 44px row on phones, as the box's invisible area (ui/checkbox). */}
                <div className="flex items-start gap-3 max-md:min-h-11">
                    <Checkbox
                        id="gallery-consent"
                        checked={form.data.consent}
                        onCheckedChange={(checked) =>
                            form.setData('consent', checked === true)
                        }
                        className="mt-0.5"
                        {...fieldA11y('gallery-consent', form.errors.consent)}
                    />
                    <Label htmlFor="gallery-consent" className="leading-snug">
                        {t('provider.photos.consent')}
                    </Label>
                </div>
                <InputError
                    id={errorId('gallery-consent')}
                    message={form.errors.consent}
                />
            </div>

            <Button
                type="submit"
                className="self-start"
                disabled={form.data.photo === null || busy}
            >
                {/* Hidden: the status line below says it, once. */}
                {busy && <Spinner aria-hidden="true" />}
                {t('provider.gallery.submit')}
            </Button>

            {/* Always on the page, so that the change is read when it happens. */}
            <p role="status" className="text-sm text-muted-foreground">
                {busy ? t('provider.gallery.uploading') : ''}
            </p>
        </form>
    );
}
