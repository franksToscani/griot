// Classes shared by the forms of the site (#112): one place instead of a copy in every page.

// The kit's Button keeps its label on one line at a fixed height: long Italian labels would widen
// the page at 375 px. cn() uses tailwind-merge, so these classes replace the kit's ones.
export const wrappingLabel =
    'h-auto max-w-full whitespace-normal py-2 text-center';

// A native <select> that looks like the kit's Input: the same border, ring on focus (with an
// outline in forced colours), the error border, and 44 px on phones as every target of the site.
export const selectClass =
    'border-input flex h-9 w-full min-w-0 rounded-md border bg-transparent px-3 py-1 text-base shadow-xs outline-none focus-visible:outline-hidden focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 aria-invalid:border-destructive max-md:min-h-11 md:text-sm';
