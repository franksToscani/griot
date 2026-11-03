import { useTranslation } from '@/hooks/use-translation';

// The id of the page's <main>, which has tabIndex={-1}: the skip link moves the focus there.
export const MAIN_CONTENT_ID = 'main-content';

// The first element of the page for the keyboard: hidden until it has the focus, it jumps over the
// header and the menus. The focus is moved by hand: following "#main-content" only scrolls in some
// browsers, and the next Tab would start again from the top.
export function SkipLink() {
    const { t } = useTranslation();

    return (
        <a
            href={`#${MAIN_CONTENT_ID}`}
            onClick={(event) => {
                const main = document.getElementById(MAIN_CONTENT_ID);

                if (main !== null) {
                    event.preventDefault();
                    main.focus();
                    main.scrollIntoView();
                }
            }}
            className="sr-only focus:not-sr-only focus:fixed focus:top-2 focus:left-2 focus:z-50 focus:rounded-md focus:bg-background focus:px-4 focus:py-2 focus:text-sm focus:font-medium focus:text-foreground focus:shadow-md focus:ring-[3px] focus:ring-ring"
        >
            {t('common.skip_to_content')}
        </a>
    );
}
