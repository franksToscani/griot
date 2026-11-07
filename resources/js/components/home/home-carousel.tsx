import { Link } from '@inertiajs/react';
import { ChevronLeft, ChevronRight } from 'lucide-react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { useTranslation } from '@/hooks/use-translation';
import { nextIndex, previousIndex } from '@/lib/gallery';
import { buildSlides, slidePosition } from '@/lib/home-carousel';
import { HOME_PHOTOS } from '@/lib/home-photos';
import type { SlideTone } from '@/lib/home-carousel';
import { cn } from '@/lib/utils';
import { index as providersIndex } from '@/routes/providers';
import type { Category } from '@/types';

// The colours of each tile and of its button, from the tokens only. The contrast of each pair is
// checked by AccessibilityContrastTest, which reads these lines. On the green tile the button is
// the light one and its focus ring the tile's own text colour: the usual ring would not be seen
// on green. The white tile has the border of the fields: white on the white page needs an edge.
const TONES: Record<
    SlideTone,
    { tile: string; button: 'default' | 'secondary'; ring: string }
> = {
    primary: {
        tile: 'bg-primary text-primary-foreground',
        button: 'secondary',
        ring: 'focus-visible:ring-primary-foreground',
    },
    highlight: {
        tile: 'bg-highlight text-highlight-foreground',
        button: 'default',
        ring: '',
    },
    info: {
        tile: 'bg-info text-info-foreground',
        button: 'default',
        ring: '',
    },
    card: {
        tile: 'border border-input bg-card text-card-foreground',
        button: 'default',
        ring: '',
    },
};

const LABEL_ID = 'home-carousel-label';
const slideNameId = (id: number): string => `home-carousel-slide-${id}`;

// The carousel at the top of the home page (issue #144, docs/home.md, docs/accessibility.md), in
// the pattern of the WAI-ARIA carousel without automatic rotation: the slide changes only when
// the person asks, at once, without any change effect. One slide per category with something to
// book. No title: the category's name is a paragraph, so the headings of the page stay as they
// are.
export function HomeCarousel({ categories }: { categories: Category[] }) {
    const { t } = useTranslation();
    const [chosen, setChosen] = useState(0);
    // What the status line says: empty when the page opens, "Slide 2 di 7" after each change.
    const [announcement, setAnnouncement] = useState('');
    // The slides already shown: only their photos are on the page. The first one at once; another
    // one from its first visit, then it stays, so the browser never downloads a photo nobody saw.
    const [seen, setSeen] = useState<number[]>([0]);
    const slides = buildSlides(t, categories, HOME_PHOTOS);
    const total = slides.length;

    if (total === 0) {
        return null;
    }

    // Fewer categories than before (a page visit): back to the first slide.
    const current = chosen < total ? chosen : 0;

    // The focus stays on the button pressed.
    const go = (target: number) => {
        setChosen(target);
        setSeen((shown) =>
            shown.includes(target) ? shown : [...shown, target],
        );
        setAnnouncement(slidePosition(t, target, total));
    };

    return (
        <section
            aria-roledescription={t('home.carousel.role')}
            aria-labelledby={LABEL_ID}
            className="flex min-w-0 flex-col gap-3"
        >
            <p id={LABEL_ID} className="sr-only">
                {t('home.carousel.label')}
            </p>

            {/* Every slide in the same cell: the height is the tallest one's, and never changes.
                The others are invisible: neither the Tab key nor a screen reader goes in. */}
            <div className="grid">
                {slides.map((slide, position) => {
                    const tone = TONES[slide.tone];

                    return (
                        <div
                            key={slide.id}
                            role="group"
                            aria-roledescription={t('home.carousel.slide')}
                            aria-labelledby={slideNameId(slide.id)}
                            className={cn(
                                'col-start-1 row-start-1 grid min-w-0 overflow-hidden rounded-xl forced-colors:border',
                                slide.image && 'md:grid-cols-2',
                                position !== current && 'invisible',
                            )}
                        >
                            {/* The text and the button only on the coloured tile, never on the
                                photo: their contrast is the one of the tokens. */}
                            <div
                                className={cn(
                                    'flex min-w-0 flex-col items-start gap-3 p-6 md:p-10',
                                    tone.tile,
                                )}
                            >
                                <p
                                    id={slideNameId(slide.id)}
                                    className="text-3xl font-semibold break-words"
                                >
                                    {slide.name}
                                </p>
                                <p className="max-w-xl">{slide.sentence}</p>
                                <p className="sr-only">
                                    {slidePosition(t, position, total)}
                                </p>
                                <Button
                                    asChild
                                    variant={tone.button}
                                    className={cn('max-md:w-full', tone.ring)}
                                >
                                    <Link
                                        href={providersIndex({
                                            query: { category: slide.slug },
                                        })}
                                    >
                                        {t('home.carousel.cta')}
                                        <span className="sr-only">
                                            {' '}
                                            {t('home.carousel.cta_which', {
                                                category: slide.name,
                                            })}
                                        </span>
                                    </Link>
                                </Button>
                            </div>
                            {/* The photo, decorative: above the tile on phones (3:2), the right
                                half from 768 px up, as tall as the slide. Its box keeps the space
                                before the photo is there. Nothing else in it. */}
                            {slide.image && (
                                <div className="relative order-first aspect-[3/2] bg-muted md:order-none md:aspect-auto md:min-h-80">
                                    {seen.includes(position) && (
                                        <img
                                            src={slide.image.src}
                                            srcSet={slide.image.srcSet}
                                            sizes="(min-width: 768px) 50vw, 100vw"
                                            width={slide.image.width}
                                            height={slide.image.height}
                                            alt=""
                                            decoding="async"
                                            {...(position === 0
                                                ? {
                                                      loading: 'eager',
                                                      fetchPriority: 'high',
                                                  }
                                                : { loading: 'lazy' })}
                                            className="absolute inset-0 size-full object-cover"
                                        />
                                    )}
                                </div>
                            )}
                        </div>
                    );
                })}
            </div>

            {/* Only with more than one slide: previous and next go round, so neither is ever
                disabled under the focus; a dot per slide, the current one marked. */}
            {total > 1 && (
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <Button
                        type="button"
                        variant="outline"
                        onClick={() => go(previousIndex(current, total))}
                    >
                        <ChevronLeft aria-hidden="true" />
                        {t('home.carousel.previous')}
                    </Button>
                    <ul className="flex flex-wrap items-center justify-center">
                        {slides.map((slide, position) => (
                            <li key={slide.id}>
                                {/* 44px on phones; the dot inside, bigger when current, so that
                                    it shows without colour too (forced colours). */}
                                <button
                                    type="button"
                                    aria-current={
                                        position === current
                                            ? 'true'
                                            : undefined
                                    }
                                    onClick={() => go(position)}
                                    className="flex size-11 items-center justify-center rounded-full outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-hidden md:size-8"
                                >
                                    <span
                                        aria-hidden="true"
                                        className={cn(
                                            'rounded-full border border-foreground',
                                            position === current
                                                ? 'size-3.5 bg-foreground'
                                                : 'size-2.5',
                                        )}
                                    />
                                    <span className="sr-only">
                                        {t('home.carousel.go_to', {
                                            number: position + 1,
                                        })}
                                    </span>
                                </button>
                            </li>
                        ))}
                    </ul>
                    <Button
                        type="button"
                        variant="outline"
                        onClick={() => go(nextIndex(current, total))}
                    >
                        {t('home.carousel.next')}
                        <ChevronRight aria-hidden="true" />
                    </Button>
                </div>
            )}

            {/* Always on the page, so that a change is read when it happens. */}
            <p role="status" className="sr-only">
                {announcement}
            </p>
        </section>
    );
}
