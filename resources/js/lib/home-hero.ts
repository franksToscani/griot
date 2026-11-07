// The colours of the band at the top of the home page (docs/home.md): the search on the left, the
// carousel on the right. Only the tokens "hero" and "hero-foreground" of resources/css/app.css and
// the page's own field colours. AccessibilityContrastTest measures each pair of these tokens, in
// the light and in the dark theme; HomeHeroSearchTest checks that the components use these lines.
export const HERO = {
    // The band: dark green, white text, square corners.
    band: 'bg-hero text-hero-foreground',
    // The fields: as on the rest of the site (the page's background and text, the usual border),
    // their edge against the green is the field itself. The focus ring in the band's text colour:
    // the usual ring would not show on green.
    field: 'bg-background text-foreground focus-visible:ring-hero-foreground',
    // The buttons and the link of a slide: light on the green, green text; the focus ring is the
    // same light colour, two pixels away from the button so that it shows.
    button: 'bg-hero-foreground text-hero hover:bg-hero-foreground/90 focus-visible:ring-hero-foreground focus-visible:ring-offset-2 focus-visible:ring-offset-hero',
    // The dots of the carousel: the band's text colour, its focus ring too.
    dot: 'border-hero-foreground',
    dotCurrent: 'bg-hero-foreground',
    ring: 'focus-visible:ring-hero-foreground',
} as const;
