import { Link } from '@inertiajs/react';
import { useTranslation } from '@/hooks/use-translation';
import { cn } from '@/lib/utils';
import type { Paginated } from '@/types';

type Props = Pick<Paginated<unknown>, 'links' | 'meta'>;

const linkClass = 'rounded-md border px-3 py-1.5 text-sm';

export function Pagination({ links, meta }: Props) {
    const { t } = useTranslation();

    if (meta.last_page <= 1) {
        return null;
    }

    // Laravel's first and last links are "previous" and "next" with English labels:
    // keep only the page numbers and the "..." gaps, and build previous/next with t().
    const pages = meta.links.slice(1, -1);

    return (
        <nav
            aria-label={t('common.pagination.label')}
            className="flex flex-wrap items-center justify-center gap-2"
        >
            {links.prev && (
                <Link href={links.prev} className={linkClass}>
                    {t('common.pagination.previous')}
                </Link>
            )}
            {pages.map((page, index) =>
                page.url ? (
                    <Link
                        key={index}
                        href={page.url}
                        aria-current={page.active ? 'page' : undefined}
                        className={cn(
                            linkClass,
                            page.active && 'bg-primary text-primary-foreground',
                        )}
                    >
                        {page.label}
                    </Link>
                ) : (
                    <span key={index} className="px-2 text-sm">
                        {page.label}
                    </span>
                ),
            )}
            {links.next && (
                <Link href={links.next} className={linkClass}>
                    {t('common.pagination.next')}
                </Link>
            )}
        </nav>
    );
}
