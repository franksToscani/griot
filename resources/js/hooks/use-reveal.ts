import { useEffect, useRef } from 'react';
import { startsHidden } from '@/lib/motion';

// The reveal on scroll of a group of the home page (docs/accessibility.md, "Movimento"). The
// element starts as the server and React draw it: visible. Only after the mount, only out of the
// screen, only with IntersectionObserver and without "reduce motion", it gets
// data-reveal="hidden"; when it is scrolled to, data-reveal="shown", once (unobserve). app.css
// hides and shows its .reveal-item children (or itself), inside prefers-reduced-motion:
// no-preference. If anything fails, nothing is hidden. No timer: the scroll starts it.
export function useReveal<T extends HTMLElement>() {
    const ref = useRef<T>(null);

    useEffect(() => {
        const element = ref.current;

        if (element === null) {
            return;
        }

        const rect = element.getBoundingClientRect();
        const hidden = startsHidden({
            hasObserver: typeof window.IntersectionObserver === 'function',
            reduceMotion:
                typeof window.matchMedia === 'function' &&
                window.matchMedia('(prefers-reduced-motion: reduce)').matches,
            top: rect.top,
            bottom: rect.bottom,
            viewportHeight: window.innerHeight,
        });

        if (!hidden) {
            return;
        }

        let observer: IntersectionObserver;

        try {
            observer = new IntersectionObserver((entries) => {
                for (const entry of entries) {
                    if (entry.isIntersecting) {
                        element.dataset.reveal = 'shown';
                        observer.unobserve(entry.target);
                    }
                }
            });
            observer.observe(element);
        } catch {
            // No observer: the element stays as it is, visible.
            return;
        }

        // Hidden only now that something will show it again.
        element.dataset.reveal = 'hidden';

        return () => {
            observer.disconnect();
        };
    }, []);

    return ref;
}
