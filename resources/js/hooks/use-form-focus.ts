import { useCallback, useRef } from 'react';
import { firstErrorField } from '@/lib/form-focus';

type Field = HTMLInputElement | HTMLSelectElement | HTMLTextAreaElement;

function isField(element: Element): element is Field {
    return (
        element instanceof HTMLInputElement ||
        element instanceof HTMLSelectElement ||
        element instanceof HTMLTextAreaElement
    );
}

// The focus of a form of the provider's area (#244, docs/accessibility.md, « Dopo un invio
// rifiutato »). The submit button stays disabled while sending (disabled={processing}) and so loses
// the focus; this hook puts it where it is useful:
// - onError: on the next frame (the errors are on the page by then) the first field with an error,
//   in the order of the page, found by its name (lib/form-focus.ts): a closed <details> around it is
//   opened, the field takes the focus and comes to the middle of the screen at once, never with a
//   smooth scroll. An error of no field (the request limit, errors.service, errors.publish) moves
//   nothing: the button gets the focus back (onFinish).
// - onFinish: the button gets the focus back, only if nobody took it meanwhile (the field of an
//   error, the new item after a save with useFlashFocus). onError comes before onFinish, and both
//   wait for a frame, so the field is focused first.
// No ARIA of its own and no announcement (the light rule, section 9).
export function useFormFocus() {
    const ref = useRef<HTMLButtonElement>(null);

    const onError = useCallback((errors: Record<string, string>) => {
        window.requestAnimationFrame(() => {
            const form = ref.current?.form;

            if (!form) {
                return;
            }

            const fields = Array.from(form.elements).filter(isField);
            const index = firstErrorField(
                fields.map((field) => ({
                    name: field.name,
                    disabled: field.disabled,
                    type: field.type,
                    visible: field.getClientRects().length > 0,
                    inClosedDetails:
                        field.closest('details:not([open])') !== null,
                })),
                errors,
            );

            if (index === null) {
                return;
            }

            const field = fields[index];
            const details = field.closest('details');

            if (details !== null && !details.open) {
                details.open = true;
            }

            field.focus({ preventScroll: true });
            field.scrollIntoView({ block: 'center' });
        });
    }, []);

    const onFinish = useCallback(() => {
        window.requestAnimationFrame(() => {
            const active = document.activeElement;

            if (active === null || active === document.body) {
                ref.current?.focus();
            }
        });
    }, []);

    return { ref, onError, onFinish };
}
