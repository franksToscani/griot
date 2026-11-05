import type { ReactNode } from 'react';

// A section of the home page whose items are in a row that scrolls sideways (issue #144,
// docs/accessibility.md). The row is a region named by the title of the section, and takes the
// focus itself (tabIndex 0): the arrow keys scroll it. Every link stays in the order of the Tab
// key. Only the row scrolls, never the page. Shared by "Categorie" and "Professionisti in
// evidenza".
export function ScrollRow<Item extends { id: number }>({
    titleId,
    title,
    items,
    renderItem,
}: {
    // The id of the h2, which names the region.
    titleId: string;
    title: string;
    items: Item[];
    renderItem: (item: Item) => ReactNode;
}) {
    return (
        <section className="flex min-w-0 flex-col gap-4">
            <h2 id={titleId} className="text-xl font-semibold">
                {title}
            </h2>
            <div
                role="region"
                aria-labelledby={titleId}
                tabIndex={0}
                className="overflow-x-auto rounded-xl outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-hidden"
            >
                <ul className="flex snap-x gap-3 p-1 pb-3">
                    {items.map((item) => (
                        <li key={item.id} className="shrink-0 snap-start">
                            {renderItem(item)}
                        </li>
                    ))}
                </ul>
            </div>
        </section>
    );
}
