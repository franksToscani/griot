// The motion of the home page (docs/accessibility.md, "Movimento"): the small rules, apart from
// React so that they can be tested with Node, and the classes shared by the cards. Every
// animation and transition is under motion-safe: (prefers-reduced-motion: no-preference), so with
// "reduce motion" none exists; the effects of the mouse are under hover:, which Tailwind puts in
// @media (hover: hover). Only transform, opacity and box-shadow move.
import type { CSSProperties } from 'react';

// The steps of a staggered reveal: 60 ms each, at most 5 (the sixth item and the next ones come
// with the fifth, 300 ms after the first).
export const REVEAL_STEP_MS = 60;
export const REVEAL_MAX_STEPS = 5;

// The delay of the item at this place in its group, as the CSS variable read by app.css.
export function revealDelay(index: number): CSSProperties {
    const step = Math.min(Math.max(Math.trunc(index), 0), REVEAL_MAX_STEPS);

    return { '--reveal-delay': `${step * REVEAL_STEP_MS}ms` } as CSSProperties;
}

// Whether an element starts hidden, to appear when it is scrolled to. Only when everything is
// there to show it again (IntersectionObserver), motion is welcome, and the element is out of the
// screen: what is already on screen is never hidden, so nothing flashes.
export function startsHidden({
    hasObserver,
    reduceMotion,
    top,
    bottom,
    viewportHeight,
}: {
    hasObserver: boolean;
    reduceMotion: boolean;
    top: number;
    bottom: number;
    viewportHeight: number;
}): boolean {
    if (!hasObserver || reduceMotion) {
        return false;
    }

    const onScreen = top < viewportHeight && bottom > 0;

    return !onScreen;
}

// The cards of the home page (CategoryCard, FeaturedProviderCard): under the mouse or with the
// keyboard's focus the card rises by 2px with a shadow, and its photo grows a little inside its
// frame. The shadow is there with "reduce motion" too: it does not move. Tailwind 4 moves with the
// translate and scale properties (transforms of their own), so those are the ones that change:
// translate for the card, transition-transform (transform, translate, scale, rotate) for the photo.
export const CARD_MOTION = {
    card: 'group hover:shadow-md focus-visible:shadow-md motion-safe:transition-[translate,box-shadow] motion-safe:duration-200 motion-safe:ease-out motion-safe:hover:-translate-y-0.5 motion-safe:focus-visible:-translate-y-0.5',
    frame: 'overflow-hidden',
    photo: 'motion-safe:transition-transform motion-safe:duration-300 motion-safe:ease-out motion-safe:group-hover:scale-105 motion-safe:group-focus-visible:scale-105',
} as const;
