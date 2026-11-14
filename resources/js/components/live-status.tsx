// The status region of a page that updates itself (hooks/use-live-props.ts): always in the page,
// empty until the polling brings a change, then read politely by screen readers (role="status").
// Hidden from sight: the change is already visible on the page. The focus never moves for it.
export function LiveStatus({ message }: { message: string }) {
    return (
        <div role="status" className="sr-only">
            {message}
        </div>
    );
}
