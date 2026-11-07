import { Link } from '@inertiajs/react';
import { ChevronLeft, ChevronRight } from 'lucide-react';
import { useState } from 'react';
import { Button } from '@/components/ui/button';
import { useTranslation } from '@/hooks/use-translation';
import { nextIndex, previousIndex } from '@/lib/gallery';
import { slidePosition } from '@/lib/home-carousel';
import type { Slide } from '@/lib/home-carousel';
import { HERO } from '@/lib/home-hero';
import { cn } from '@/lib/utils';
import { index as providersIndex } from '@/routes/providers';

const LABEL_ID = 'home-carousel-label';
const slideNameId = (id: number): string => `home-carousel-slide-${id}`;

// The width of the photo: the whole screen on phones, the right column from 768 px (half the
// band), never more than half of the site's container (72rem, less the gaps).
const PHOTO_SIZES = '(min-width: 1152px) 540px, (min-width: 768px) 50vw, 100vw';

// The carousel in the right column of the band at the top of the home page (docs/home.md,
// docs/accessibility.md), in the pattern of the WAI-ARIA carousel without automatic rotation: the
// slide changes only when the person asks, at once, without any change effect. One slide per
// category with a photo: the photo, then under it the name, a sentence and the link to the list.
// The text is never on the photo. No title: the category's name is a paragraph, so the headings
// of the page stay as they are.
export function HomeCarousel({ slides }: { slides: Slide[] }) {
    const { t } = useTranslation();
    const [chosen, setChosen] = useState(0);
    // What the status line says: empty when the page opens, "Slide 2 di 3" after each change.
    const [announcement, setAnnouncement] = useState('');
    // The slides already shown: only their photos are on the page. The first one at once; another
    // one from its first visit, then it stays, so the browser never downloads a photo nobody saw.
    const [seen, setSeen] = useState<number[]>([0]);
    const total = slides.length;

    if (total === 0) {
        return null;
    }

    // Fewer slides than before (a page visit): back to the first one.
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
                {slides.map((slide, position) => (
                    <div
                        key={slide.id}
                        role="group"
                        aria-roledescription={t('home.carousel.slide')}
                        aria-labelledby={slideNameId(slide.id)}
                        className={cn(
                            'col-start-1 row-start-1 flex min-w-0 flex-col gap-3',
                            position !== current && 'invisible',
                        )}
                    >
                        {/* The photo, decorative, square corners, 3:2. Its box keeps the space
                            before the photo is there. Nothing else in it. */}
                        <div className="relative aspect-[3/2] bg-muted">
                            {seen.includes(position) && (
                                <img
                                    src={slide.image.src}
                                    srcSet={slide.image.srcSet}
                                    sizes={PHOTO_SIZES}
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
                        {/* Under the photo, on the green of the band. */}
                        <div className="flex min-w-0 flex-col items-start gap-2">
                            <p
                                id={slideNameId(slide.id)}
                                className="text-xl font-semibold break-words"
                            >
                                {slide.name}
                            </p>
                            <p>{slide.sentence}</p>
                            <p className="sr-only">
                                {slidePosition(t, position, total)}
                            </p>
                            <Button
                                asChild
                                className={cn('max-md:w-full', HERO.button)}
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
                    </div>
                ))}
            </div>

            {/* Only with more than one slide: previous and next go round, so neither is ever
                disabled under the focus; a dot per slide, the current one marked. */}
            {total > 1 && (
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <Button
                        type="button"
                        className={HERO.button}
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
                                    className={cn(
                                        'flex size-11 items-center justify-center rounded-full outline-none focus-visible:ring-[3px] focus-visible:outline-hidden md:size-8',
                                        HERO.ring,
                                    )}
                                >
                                    <span
                                        aria-hidden="true"
                                        className={cn(
                                            'rounded-full border',
                                            HERO.dot,
                                            position === current
                                                ? cn(
                                                      'size-3.5',
                                                      HERO.dotCurrent,
                                                  )
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
                        className={HERO.button}
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
