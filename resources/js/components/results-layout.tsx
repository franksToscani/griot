import type { ReactNode } from 'react';

// The columns of the results of the list (docs/providers-list.md), ready for the map of a later
// sprint. Without an aside: one column, the list. With one: from 1024 px the list on the left
// and the aside on the right (40%); under 1024 px the aside under the list. The list always comes
// first in the page, so the Tab key and a screen reader reach it first. Nothing sticks: an aside
// that stayed on screen could cover the focus.
export function ResultsLayout({
    children,
    aside,
}: {
    children: ReactNode;
    aside?: ReactNode;
}) {
    if (aside === undefined) {
        return <div className="min-w-0">{children}</div>;
    }

    return (
        <div className="flex flex-col gap-6 lg:grid lg:grid-cols-[minmax(0,1fr)_minmax(0,40%)]">
            <div className="min-w-0">{children}</div>
            <aside className="min-w-0">{aside}</aside>
        </div>
    );
}
