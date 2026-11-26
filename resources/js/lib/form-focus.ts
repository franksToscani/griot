// Which field gets the focus after a submit refused by the server (#244, docs/accessibility.md,
// « Dopo un invio rifiutato »): the first field with an error in the order of the page, found by its
// name. No ARIA is needed for it: the fields of the forms have their name already. Apart from React
// and the DOM so that it can be tested with Node, as lib/gallery.ts.

// A field of the form, as the page sees it.
export type FieldInfo = {
    name: string;
    disabled: boolean;
    type: string;
    // On the page with a size: getClientRects().length > 0 (a field in a closed <details> has none).
    visible: boolean;
    // Inside a closed <details>: it can be shown by opening it.
    inClosedDetails?: boolean;
};

// The key of the server's errors for a field name: "options[0][name]" is "options.0.name".
export function errorKey(name: string): string {
    return name.replace(/\[([^\]]*)\]/g, '.$1');
}

// The index of the first field with an error, in the order of the list (the order of the page),
// whatever the order of the errors; null when no field can take the focus (an error of no field,
// as the request limit, or a field that is disabled, hidden or not on the page).
export function firstErrorField(
    fields: FieldInfo[],
    errors: Record<string, string | undefined>,
): number | null {
    for (let index = 0; index < fields.length; index++) {
        const field = fields[index];

        if (
            field.name !== '' &&
            errors[errorKey(field.name)] !== undefined &&
            !field.disabled &&
            field.type !== 'hidden' &&
            (field.visible || field.inClosedDetails === true)
        ) {
            return index;
        }
    }

    return null;
}
