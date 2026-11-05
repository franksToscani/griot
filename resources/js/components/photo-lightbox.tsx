import { ChevronLeft, ChevronRight } from 'lucide-react';
import { useRef, useState } from 'react';
import type { KeyboardEvent, RefObject } from 'react';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { useTranslation } from '@/hooks/use-translation';
import {
    fallbackSrc,
    nextIndex,
    photoAlt,
    photoPosition,
    previousIndex,
    srcSet,
} from '@/lib/gallery';
import type { ProviderPhotoPublic } from '@/types';

// The zoom of the photos of the work (issue #143, docs/gallery.md), in the kit's Dialog: the focus
// stays inside, Esc closes, and the focus goes back to what opened it (the photo or "Vedi tutte le
// foto"). One photo at a time, changed only by the person: no
// timer, no movement. Previous and next go round, so neither is ever disabled under the focus;
// with one photo they are not there.
export function PhotoLightbox({
    photos,
    providerName,
    index,
    onIndexChange,
    onClose,
    opener,
}: {
    photos: ProviderPhotoPublic[];
    providerName: string;
    // The open photo, or null when closed.
    index: number | null;
    onIndexChange: (index: number) => void;
    onClose: () => void;
    // The button that opened the zoom. The dialog has no Trigger of its own (one dialog, many
    // buttons), so Radix would not know where to give the focus back: this does it.
    opener: RefObject<HTMLElement | null>;
}) {
    const { t } = useTranslation();
    // What the status line says: empty when the zoom opens (the title is read then), "Foto 3 di
    // 6" after each change.
    const [announcement, setAnnouncement] = useState('');
    const nextButton = useRef<HTMLButtonElement>(null);
    const total = photos.length;
    const current = index ?? 0;
    const photo = photos[current];
    const several = total > 1;

    const go = (target: number) => {
        onIndexChange(target);
        setAnnouncement(photoPosition(t, target, total));
    };
    const previous = () => go(previousIndex(current, total));
    const next = () => go(nextIndex(current, total));

    // A shortcut, with the focus inside the dialog only: the event comes from its content.
    const onKeyDown = (event: KeyboardEvent<HTMLDivElement>) => {
        if (
            !several ||
            event.altKey ||
            event.ctrlKey ||
            event.metaKey ||
            event.shiftKey
        ) {
            return;
        }

        if (event.key === 'ArrowLeft') {
            event.preventDefault();
            previous();
        } else if (event.key === 'ArrowRight') {
            event.preventDefault();
            next();
        }
    };

    if (photo === undefined) {
        return null;
    }

    const alt = photoAlt(
        t,
        photo.alt_text,
        current + 1,
        total,
        'providers.show.photo_alt',
        { provider: providerName },
    );

    return (
        <Dialog
            open={index !== null}
            onOpenChange={(open) => {
                if (!open) {
                    setAnnouncement('');
                    onClose();
                }
            }}
        >
            {/* As wide as the screen allows, and never taller: with the zoom at 200% the content
                scrolls inside instead of going out of the screen. */}
            <DialogContent
                className="max-h-[calc(100dvh-2rem)] overflow-y-auto sm:max-w-4xl"
                onKeyDown={onKeyDown}
                onCloseAutoFocus={(event) => {
                    event.preventDefault();
                    opener.current?.focus();
                }}
                onOpenAutoFocus={(event) => {
                    // On "Foto successiva", the button used most; with one photo, the kit's X.
                    if (nextButton.current !== null) {
                        event.preventDefault();
                        nextButton.current.focus();
                    }
                }}
            >
                <DialogHeader>
                    <DialogTitle>
                        {photoPosition(t, current, total)}
                    </DialogTitle>
                    <DialogDescription className="sr-only">
                        {several ? t('providers.show.lightbox.keys') : alt}
                    </DialogDescription>
                </DialogHeader>

                {/* key: a new image for a new photo, shown when ready, without any change effect.
                    Only the open photo is loaded, at once; the others when they are opened. */}
                <img
                    key={photo.id}
                    src={fallbackSrc(photo.variants)}
                    srcSet={srcSet(photo.variants)}
                    sizes="100vw"
                    width={photo.width}
                    height={photo.height}
                    alt={alt}
                    loading="eager"
                    decoding="async"
                    className="mx-auto h-auto max-h-[60dvh] w-auto max-w-full rounded-md bg-muted object-contain forced-colors:border"
                />

                {/* Always on the page while open, so that a change is read when it happens. */}
                <p role="status" className="sr-only">
                    {announcement}
                </p>

                {several && (
                    <div className="flex flex-wrap justify-between gap-2">
                        <Button
                            type="button"
                            variant="outline"
                            onClick={previous}
                        >
                            <ChevronLeft aria-hidden="true" />
                            {t('providers.show.lightbox.previous')}
                        </Button>
                        <Button
                            ref={nextButton}
                            type="button"
                            variant="outline"
                            onClick={next}
                        >
                            {t('providers.show.lightbox.next')}
                            <ChevronRight aria-hidden="true" />
                        </Button>
                    </div>
                )}
            </DialogContent>
        </Dialog>
    );
}
