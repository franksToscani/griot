import { ChevronLeft, ChevronRight, Pause, Play } from 'lucide-react';
import { useCallback, useRef, useState } from 'react';
import type { ReactNode } from 'react';
import { SectionTitle } from '@/components/home/section-title';
import { Button } from '@/components/ui/button';
import { useAutoScroll } from '@/hooks/use-auto-scroll';
import { useReveal } from '@/hooks/use-reveal';
import { useScrollRow } from '@/hooks/use-scroll-row';
import { useTranslation } from '@/hooks/use-translation';
import { revealDelay } from '@/lib/motion';
import { cn } from '@/lib/utils';

// The buttons of a row: 44px on phones, 36px from 768 px, the icon a little bigger than the kit's.
const CONTROL_SIZE = 'size-11 md:size-9 [&_svg]:size-5';

// A button at an edge of the row (aria-disabled, never disabled: it keeps the focus and stays in
// the order of the Tab key), shown faded.
const AT_EDGE = 'aria-disabled:cursor-not-allowed aria-disabled:opacity-50';

// A section of the home page whose items are in a row that scrolls sideways (issue #144,
// docs/accessibility.md, docs/home.md). Shared by "Categorie" and "Professionisti in evidenza".
// - The row is a region named by the title of the section, and takes the focus itself
//   (tabIndex 0): the arrow keys scroll it. Every link stays in the order of the Tab key. Only the
//   row scrolls, never the page. Its items appear one after the other when the row is scrolled to
//   (useReveal), and the row keeps 8px around them: a card that rises under the focus keeps its
//   whole ring.
// - Two arrows on its sides, outside the part that scrolls, from 768 px (on phones a finger
//   scrolls the row); a drag with the mouse; both through useScrollRow, at once, never smoothly.
// - With autoScroll (the categories only) the row scrolls by itself, slowly, until its end
//   (useAutoScroll), only without "reduce motion". The button before the row pauses it and starts
//   it again; it is always on the page, invisible when there is nothing to pause, so nothing
//   moves when it appears. The focus in the row, a touch, a drag or an arrow pause it for good;
//   the mouse over the row only while it is there.
// - The cards snap to the start of the row (snap-x on the row, snap-start on the cards), but not
//   while the row moves by itself or is dragged: the snap would fight the move.
// - The names of the buttons are sr-only texts from t(), with the title of the section.
// - The row is "relative": an sr-only text inside a card is position: absolute, and without a
//   positioned ancestor it would be placed against the page, far to the right of the screen,
//   and make the whole page wider (the page moved sideways on iPhone, 26/11/2026).
export function ScrollRow<Item extends { id: number }>({
    titleId,
    title,
    items,
    renderItem,
    autoScroll = false,
}: {
    // The id of the h2, which names the region.
    titleId: string;
    title: string;
    items: Item[];
    renderItem: (item: Item) => ReactNode;
    // The slow automatic scroll: the categories only.
    autoScroll?: boolean;
}) {
    const { t } = useTranslation();
    const reveal = useReveal<HTMLUListElement>();
    const rowRef = useRef<HTMLDivElement>(null);
    // A pause asked by the person, or the end of the row: for good, until "Riprendi".
    const [paused, setPaused] = useState(false);
    const pause = useCallback(() => setPaused(true), []);
    const row = useScrollRow(rowRef, pause);
    const auto = useAutoScroll(rowRef, {
        enabled: autoScroll,
        overflow: row.overflow,
        paused,
        onEnd: pause,
    });
    // Paused at the end of the row: nothing to start again.
    const resumeAtEnd = paused && row.atEnd;
    const snap = !row.dragging && !auto.active;

    return (
        <section className="flex min-w-0 flex-col gap-4">
            <div className="flex items-center justify-between gap-4">
                <SectionTitle id={titleId}>{title}</SectionTitle>
                {autoScroll && (
                    <Button
                        type="button"
                        variant="outline"
                        size="icon"
                        aria-disabled={resumeAtEnd ? true : undefined}
                        className={cn(
                            'shrink-0',
                            CONTROL_SIZE,
                            AT_EDGE,
                            !auto.available && 'invisible',
                        )}
                        onClick={() => {
                            if (!resumeAtEnd) {
                                setPaused(!paused);
                            }
                        }}
                    >
                        {paused ? (
                            <Play aria-hidden="true" />
                        ) : (
                            <Pause aria-hidden="true" />
                        )}
                        <span className="sr-only">
                            {t(paused ? 'home.row.play' : 'home.row.pause', {
                                section: title,
                            })}
                        </span>
                    </Button>
                )}
            </div>
            <div className="flex items-center gap-2" {...auto.hover}>
                <RowArrow
                    direction="previous"
                    label={t('home.row.previous', { section: title })}
                    atEdge={row.atStart}
                    shown={row.overflow}
                    onClick={() => row.scrollByArrow('previous')}
                />
                <div
                    role="region"
                    aria-labelledby={titleId}
                    tabIndex={0}
                    className="relative min-w-0 flex-1 overflow-x-auto rounded-xl outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-hidden data-dragging:cursor-grabbing data-dragging:select-none data-[snap=on]:snap-x pointer-fine:cursor-grab"
                    ref={rowRef}
                    data-snap={snap ? 'on' : 'off'}
                    data-dragging={row.dragging ? '' : undefined}
                    onFocus={pause}
                    {...row.handlers}
                >
                    <ul ref={reveal} className="flex gap-3 p-2 pb-4">
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
                <RowArrow
                    direction="next"
                    label={t('home.row.next', { section: title })}
                    atEdge={row.atEnd}
                    shown={row.overflow}
                    onClick={() => row.scrollByArrow('next')}
                />
            </div>
        </section>
    );
}

// An arrow beside the row, from 768 px only. At its edge it says so (aria-disabled) and does
// nothing; invisible when the row does not scroll, but its place is kept.
function RowArrow({
    direction,
    label,
    atEdge,
    shown,
    onClick,
}: {
    direction: 'previous' | 'next';
    label: string;
    atEdge: boolean;
    shown: boolean;
    onClick: () => void;
}) {
    const Icon = direction === 'previous' ? ChevronLeft : ChevronRight;

    return (
        <Button
            type="button"
            variant="outline"
            size="icon"
            aria-disabled={atEdge ? true : undefined}
            className={cn(
                'hidden shrink-0 md:inline-flex',
                CONTROL_SIZE,
                AT_EDGE,
                !shown && 'invisible',
            )}
            onClick={() => {
                if (!atEdge) {
                    onClick();
                }
            }}
        >
            <Icon aria-hidden="true" />
            <span className="sr-only">{label}</span>
        </Button>
    );
}
