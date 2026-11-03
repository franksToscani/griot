import type { SVGAttributes } from 'react';

// Griot's small mark: a "G" on a green square, drawn as a path (it does not depend on the font).
// The same drawing as public/favicon.svg, with the colours of the theme. Decorative: the name of
// the site is always written next to it, or given to the link that holds it.
export default function AppLogoIcon(props: SVGAttributes<SVGElement>) {
    return (
        <svg
            {...props}
            viewBox="0 0 64 64"
            xmlns="http://www.w3.org/2000/svg"
            aria-hidden="true"
        >
            <rect width="64" height="64" rx="14" className="fill-primary" />
            <path
                d="M41.9 22.1A14 14 0 1 0 46 32H33"
                fill="none"
                strokeWidth="7"
                strokeLinecap="round"
                strokeLinejoin="round"
                className="stroke-primary-foreground"
            />
        </svg>
    );
}
