// The menu of the provider's area on phones (the kit's Sheet in components/ui/sidebar.tsx) closes
// by itself when a page is chosen (#244, docs/accessibility.md, « Il tasto Menu in basso »). When it
// closes because of a navigation, the focus goes to the content of the page (<main>), not back to
// the "Menu" button that opened it: the panel's onCloseAutoFocus asks here. Closed with the X, a
// click outside or Esc, nothing is marked, and the panel gives the focus back to the button kept
// when it opened (components/ui/sidebar.tsx: Radix alone would give it only to a DialogTrigger).

let closedByNavigation = false;

// Called just before closing the open panel for a navigation (a link of the menu, router "navigate").
export function markClosedByNavigation(): void {
    closedByNavigation = true;
}

// Read once by the panel when it closes: true only for the closing just marked.
export function consumeClosedByNavigation(): boolean {
    const marked = closedByNavigation;
    closedByNavigation = false;

    return marked;
}
