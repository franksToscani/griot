// A link that stands alone (not inside a sentence) gets a target of at least 44x44px on phones and
// tablets, where it is tapped with a finger (issue #55). The width too: a short word ("Esci",
// "Log in") is narrower than 44px, and how much depends on the font. Below the md breakpoint
// only: from 768px up the page looks as before. Links inside a sentence are left as they are.
export const touchTarget =
    'max-md:inline-flex max-md:min-h-11 max-md:min-w-11 max-md:items-center';
