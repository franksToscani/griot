// How a field is tied to its help text and to its error, for screen readers (issue #55). The error
// of the field with id "x" has the id "x-error" (InputError's id).

export const errorId = (fieldId: string): string => `${fieldId}-error`;

// aria-invalid only while there is an error; aria-describedby lists the help (if any) and the
// error (if any), in this order: the help is never replaced.
export function fieldA11y(
    fieldId: string,
    error?: string,
    helpId?: string,
): { 'aria-invalid'?: true; 'aria-describedby'?: string } {
    const describedBy = [helpId, error ? errorId(fieldId) : undefined]
        .filter((id) => id !== undefined)
        .join(' ');

    return {
        ...(error ? { 'aria-invalid': true } : {}),
        ...(describedBy !== '' ? { 'aria-describedby': describedBy } : {}),
    };
}

// After a submit refused with errors: the focus goes to the first field marked invalid, in the
// order of the form. The field's label, its state and its error are read once, through
// aria-describedby. Next frame: the errors from the server are on the page by then.
export function focusFirstInvalid(formId: string): void {
    requestAnimationFrame(() => {
        document
            .querySelector<HTMLElement>(`#${formId} [aria-invalid="true"]`)
            ?.focus();
    });
}
