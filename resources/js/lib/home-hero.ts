// The colours of the band at the top of the home page (docs/home.md): the search on the left, the
// carousel on the right. Only the tokens "hero" and "hero-foreground" of resources/css/app.css and
// the page's own field colours. AccessibilityContrastTest measures each pair of these tokens, in
// the light and in the dark theme; HomeHeroSearchTest checks that the components use these lines.
// A button of the band pressed: a little smaller, for a moment (only without "reduce motion").
const PRESS =
    'motion-safe:transition-[color,box-shadow,scale] motion-safe:duration-150 motion-safe:active:scale-[0.98]';

export const HERO = {
    // The band: dark brown, cream text, square corners. In the dark theme, close to the page's
    // own brown: a thin border above and under it (border-input, measured against both).
    band: 'bg-hero text-hero-foreground dark:border-y dark:border-input',
    // The fields: as on the rest of the site (the page's background and text, the usual border).
    // Their edge against the band is their border (border-input): in the dark theme the field's
    // own background is too close to the band. The focus ring in the band's text colour: the
    // usual ring would not show on brown.
    field: 'bg-background text-foreground focus-visible:ring-hero-foreground',
    // The buttons and the link of a slide: cream on the brown, brown text; the focus ring is the
    // same light colour, two pixels away from the button so that it shows. Pressed, it shrinks.
    button: `bg-hero-foreground text-hero hover:bg-hero-foreground/90 focus-visible:ring-hero-foreground focus-visible:ring-offset-2 focus-visible:ring-offset-hero ${PRESS}`,
    // The same press for the dots of the carousel.
    press: PRESS,
    // The dots of the carousel: the band's text colour, its focus ring too.
    dot: 'border-hero-foreground',
    dotCurrent: 'bg-hero-foreground',
    ring: 'focus-visible:ring-hero-foreground',
    // "Vedi tutti i professionisti" under the search: cream text, underlined, the band's focus ring.
    link: 'w-fit underline underline-offset-4 outline-none focus-visible:ring-[3px] focus-visible:ring-hero-foreground focus-visible:outline-hidden',
} as const;
