import type { Page, ReloadOptions } from '@inertiajs/core';
import { router, usePoll } from '@inertiajs/react';
import {
    useCallback,
    useEffect,
    useReducer,
    useRef,
    useState,
    useSyncExternalStore,
} from 'react';

// How often the pages ask the server again for the state of their bookings (docs/bookings.md).
export const PROVIDER_INTERVAL = 20_000;
export const CUSTOMER_INTERVAL = 30_000;

// After this long without a click, a key, a pointer move or a touch the polling stops, so that an
// open tab does not keep the session alive for ever. The next interaction starts it again, with a
// reload at once.
export const IDLE_AFTER = 30 * 60_000;
const INTERACTIONS = [
    'pointerdown',
    'pointermove',
    'keydown',
    'touchstart',
] as const;

// ---------------------------------------------------------------------------------------------
// Pauses: a window of the page open, or a form being sent
// ---------------------------------------------------------------------------------------------

let pauses = 0;
const listeners = new Set<() => void>();

function subscribe(listener: () => void): () => void {
    listeners.add(listener);

    return () => listeners.delete(listener);
}

function changePauses(by: number): void {
    pauses += by;
    listeners.forEach((listener) => listener());
}

// Holds the polling of the page while "when" is true: a reject or cancel window open, a form being
// sent. A new state could otherwise remove the window, and the text written in it, under the user.
// A component, so that it can be used inside the render function of <Form>.
export function PauseLiveProps({ when }: { when: boolean }): null {
    useEffect(() => {
        if (!when) {
            return;
        }

        changePauses(1);

        return () => changePauses(-1);
    }, [when]);

    return null;
}

// ---------------------------------------------------------------------------------------------
// The hook
// ---------------------------------------------------------------------------------------------

type Options = {
    interval: number;
    // false when the state can no longer change (see the pages).
    enabled: boolean;
    // The text for the status region when the new props differ from the ones shown, or null.
    // Called before the page is updated, with the new props.
    describe: (next: Page['props']) => string | null;
};

// Asks the server again, every "interval" ms, for the props "only" of the page shown, with
// Inertia's polling: a partial reload that keeps the scroll and the state of the components.
// - background "pause": nothing while the tab is hidden; when it is visible again, a reload at once
//   if the interval has passed.
// - It stops for good at the first error (a page that no longer exists, a server error, no
//   network), without the toast of lib/http-errors.ts: returning false stops Inertia's global
//   "httpException" and "networkError" events for these requests only.
// - It holds while a window is open or a form is being sent (PauseLiveProps), and after IDLE_AFTER
//   without interaction.
// Returns the text of the status region: empty at first, written only when "describe" finds a
// change brought by the polling, never on the first render.
export function useLiveProps(
    only: string[],
    { interval, enabled, describe }: Options,
): string {
    const [message, setMessage] = useState('');
    const [failed, setFailed] = useState(false);
    const [idle, setIdle] = useState(false);
    // A new render after each answer, so that "enabled" is computed again (a time that has passed).
    const [, refresh] = useReducer((count: number) => count + 1, 0);
    const paused = useSyncExternalStore(
        subscribe,
        () => pauses > 0,
        () => false,
    );

    const describeRef = useRef(describe);
    const canReload = useRef(false);
    const running = enabled && !paused && !idle && !failed;

    useEffect(() => {
        describeRef.current = describe;
        canReload.current = enabled && !paused && !failed;
    });

    // The same text twice would not be read again: the region is emptied first.
    const announce = useCallback((text: string) => {
        setMessage('');
        window.setTimeout(() => setMessage(text), 100);
    }, []);

    // A text, not the array: the page writes a new array at each render.
    const onlyKey = only.join(',');

    const requestOptions = useCallback(
        (): ReloadOptions => ({
            only: onlyKey.split(','),
            onBeforeUpdate: (page) => {
                const text = describeRef.current(page.props);

                if (text !== null) {
                    announce(text);
                }
            },
            onSuccess: () => refresh(),
            onHttpException: () => {
                setFailed(true);

                return false;
            },
            onNetworkError: () => {
                setFailed(true);

                return false;
            },
        }),
        [announce, onlyKey],
    );

    const { start, stop } = usePoll(interval, requestOptions, {
        background: 'pause',
        autoStart: false,
    });

    useEffect(() => {
        if (running) {
            start();
        } else {
            stop();
        }
    }, [running, start, stop]);

    // Inactivity: checked once a minute; any interaction ends it, with a reload at once.
    useEffect(() => {
        let last = Date.now();
        let isIdle = false;

        const timer = window.setInterval(() => {
            if (!isIdle && Date.now() - last >= IDLE_AFTER) {
                isIdle = true;
                setIdle(true);
            }
        }, 60_000);

        const onInteraction = () => {
            last = Date.now();

            if (isIdle) {
                isIdle = false;
                setIdle(false);

                if (canReload.current) {
                    router.reload(requestOptions());
                }
            }
        };

        INTERACTIONS.forEach((name) =>
            window.addEventListener(name, onInteraction, { passive: true }),
        );

        return () => {
            window.clearInterval(timer);
            INTERACTIONS.forEach((name) =>
                window.removeEventListener(name, onInteraction),
            );
        };
    }, [requestOptions]);

    return message;
}
