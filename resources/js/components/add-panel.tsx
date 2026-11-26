import { Plus } from 'lucide-react';
import { useRef, useState } from 'react';
import type { ReactNode } from 'react';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader } from '@/components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { flashFocusId, revealAndFocus } from '@/hooks/use-flash-focus';
import { useWideLayout } from '@/hooks/use-wide-layout';
import { wrappingLabel } from '@/lib/form-classes';
import { touchTarget } from '@/lib/touch-target';

// The "add" form of the provider's services and hours (#53, docs/accessibility.md, « Elementi che
// restano fermi »). From xl (1280 px) a card in the right column, next to the list and never over
// it, that stays in view while the list scrolls and scrolls inside itself when taller than the
// screen. Below xl a button at the top of the page opens the same form in a dialog. Only one of the
// two is ever on the page (useWideLayout), so every field keeps a unique id.

// The only element of the private area that stays in view: always with the xl: prefix, a maximum
// height and its own scroll. Nothing fixed, no animation of its own.
export const STICKY_PANEL =
    'xl:sticky xl:top-4 xl:max-h-[calc(100svh-2rem)] xl:overflow-y-auto';

// The widths of the services and hours pages, in this one place. From xl two columns: the list on
// the left (first in the DOM), never wider than its cards, the form on the right, 1.5rem apart; the
// group stays on the left, under the title and the breadcrumbs. 42rem must stay equal to max-w-2xl
// below (the list and the title): ProviderAddPanelTest compares them.
export const TWO_COLUMNS =
    'flex flex-col gap-6 xl:grid xl:grid-cols-[minmax(0,42rem)_24rem] xl:items-start xl:justify-start';

// The column of the list: as wide as its cards (42rem), narrower when the page is.
export const LIST_COLUMN = 'flex max-w-2xl min-w-0 flex-col gap-4';

// The title of the page and the messages above the columns: the width of the list.
export const PAGE_HEADER_WIDTH = 'max-w-2xl';

// The title of the column's form, reached by the "Add…" link from the keyboard: the kit's ring.
export const PANEL_TITLE_FOCUS =
    'scroll-mt-4 rounded-sm outline-none focus-visible:outline-hidden focus-visible:ring-[3px] focus-visible:ring-ring/50';

// The title of a card that a save focuses (useFlashFocus): reached by script only, never with Tab,
// so no ring of its own; its card shows JUST_SAVED instead. Still visible in forced colours.
export const CARD_TITLE_FOCUS =
    'scroll-mt-4 rounded-sm outline-none focus-visible:outline-hidden';

// The card just saved (data-just-saved, set and removed by useFlashFocus): an outline, not a ring,
// so it stays in forced colours; the full colour of the ring token; it appears and goes at once.
export const JUST_SAVED =
    'data-[just-saved]:outline-[3px] data-[just-saved]:outline-offset-2 data-[just-saved]:outline-ring';

// What the form gets: the flash data of a successful save, or nothing.
export type OnSaved = (flash: unknown) => void;

type PanelProps = {
    // "card" goes after the list, "dialog" at the top of the page: each renders only in its layout.
    mode: 'card' | 'dialog';
    // The base of the ids: "<id>-title" is the title of the form.
    id: string;
    title: string;
    // The button that opens the dialog, below xl.
    openLabel: string;
    children: (onSaved: OnSaved) => ReactNode;
};

export function AddPanel({ mode, ...props }: PanelProps) {
    const wide = useWideLayout();

    // Never the two forms together.
    if (mode === 'card' ? !wide : wide) {
        return null;
    }

    return mode === 'card' ? (
        <PanelCard {...props} />
    ) : (
        <PanelDialog {...props} />
    );
}

function PanelCard({ id, title, children }: Omit<PanelProps, 'mode'>) {
    return (
        <aside aria-labelledby={`${id}-title`} className={STICKY_PANEL}>
            <Card>
                <CardHeader>
                    <h2
                        id={`${id}-title`}
                        tabIndex={-1}
                        className={`text-xl leading-none font-semibold ${PANEL_TITLE_FOCUS}`}
                    >
                        {title}
                    </h2>
                </CardHeader>
                {/* After a save the page moves the focus to the new item (useFlashFocus). */}
                <CardContent>{children(() => {})}</CardContent>
            </Card>
        </aside>
    );
}

function PanelDialog({ title, openLabel, children }: Omit<PanelProps, 'mode'>) {
    const [open, setOpen] = useState(false);
    // The element to focus once the dialog is closed after a save.
    const target = useRef<string | null>(null);

    const onSaved: OnSaved = (flash) => {
        target.current = flashFocusId(flash);
        setOpen(false);
    };

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button className={`self-start ${wrappingLabel}`}>
                    <Plus aria-hidden="true" />
                    {openLabel}
                </Button>
            </DialogTrigger>
            {/* Its own height and scroll: a long form never leaves a phone's screen. No
                description: the title names the dialog, the fields explain themselves. */}
            <DialogContent
                aria-describedby={undefined}
                className="max-h-[calc(100svh-2rem)] overflow-y-auto"
                onCloseAutoFocus={(event) => {
                    const id = target.current;
                    target.current = null;

                    // After a save: the new item, not the "add" button (Esc and Cancel keep the
                    // default, back to the button).
                    if (id && document.getElementById(id)) {
                        event.preventDefault();
                        window.requestAnimationFrame(() => revealAndFocus(id));
                    }
                }}
            >
                {/* No id of our own (audit #175): Radix gives the title its id and names the dialog
                    with it (aria-labelledby). An id written here replaced Radix's, and the dialog
                    pointed to a title that did not exist. "<id>-title" is the column's title only. */}
                <DialogHeader>
                    <DialogTitle>{title}</DialogTitle>
                </DialogHeader>
                {children(onSaved)}
            </DialogContent>
        </Dialog>
    );
}

// From xl, at the top of the list: to the form's title, for whoever moves with the keyboard and
// would otherwise go through the whole list first.
export function AddPanelLink({ id, label }: { id: string; label: string }) {
    const wide = useWideLayout();

    if (!wide) {
        return null;
    }

    return (
        <a
            href={`#${id}-title`}
            onClick={(event) => {
                event.preventDefault();
                document.getElementById(`${id}-title`)?.focus();
            }}
            className={`self-start text-sm font-medium underline underline-offset-4 ${touchTarget}`}
        >
            {label}
        </a>
    );
}

// The focus of a form after a refused submit and at the end of every submit: useFormFocus
// (hooks/use-form-focus.ts, #244). Exported here too, under its old name as well, for the forms that
// took useSubmitFocus from this file.
export {
    useFormFocus,
    useFormFocus as useSubmitFocus,
} from '@/hooks/use-form-focus';
