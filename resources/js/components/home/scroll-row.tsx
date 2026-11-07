import type { ReactNode } from 'react';
import { useReveal } from '@/hooks/use-reveal';
import { revealDelay } from '@/lib/motion';

// A section of the home page whose items are in a row that scrolls sideways (issue #144,
// docs/accessibility.md). The row is a region named by the title of the section, and takes the
// focus itself (tabIndex 0): the arrow keys scroll it. Every link stays in the order of the Tab
// key. Only the row scrolls, never the page. Shared by "Categorie" and "Professionisti in
// evidenza". Its items appear one after the other when the row is scrolled to (useReveal), and
// the row keeps 8px around them: a card that rises under the focus keeps its whole ring.
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
    const reveal = useReveal<HTMLUListElement>();

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
                <ul ref={reveal} className="flex snap-x gap-3 p-2 pb-4">
                    {items.map((item, index) => (
                        <li
                            key={item.id}
                            className="reveal-item shrink-0 snap-start"
                            style={revealDelay(index)}
                        >
                            {renderItem(item)}
                        </li>
                    ))}
                </ul>
            </div>
        </section>
    );
}
