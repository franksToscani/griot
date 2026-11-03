import type { HTMLAttributes } from 'react';
import { cn } from '@/lib/utils';

// The error of a field, or of a whole form. A field's error has an id (errorId() of the field) and
// is read when the focus reaches the field, through its aria-describedby: no role, so that it is
// not announced twice. An error that belongs to no field (a refused action, the request limit) is
// "announce": role="alert", read as soon as it appears.
export default function InputError({
    message,
    className = '',
    announce = false,
    ...props
}: HTMLAttributes<HTMLParagraphElement> & {
    message?: string;
    announce?: boolean;
}) {
    return message ? (
        <p
            {...props}
            role={announce ? 'alert' : undefined}
            className={cn('text-sm text-red-600 dark:text-red-400', className)}
        >
            {message}
        </p>
    ) : null;
}
