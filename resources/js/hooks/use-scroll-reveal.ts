import { useEffect } from 'react';

let sharedObserver: IntersectionObserver | null = null;
const observedElements = new Set<Element>();

function prefersReducedMotion(): boolean {
    return (
        typeof window.matchMedia === 'function' &&
        window.matchMedia('(prefers-reduced-motion: reduce)').matches
    );
}

function reveal(element: Element) {
    element.classList.add('is-revealed');
    sharedObserver?.unobserve(element);
    observedElements.delete(element);
}

function revealImmediately(root: ParentNode) {
    root.querySelectorAll('[data-reveal]:not(.is-revealed)').forEach(reveal);
}

function observer(): IntersectionObserver | null {
    if (typeof IntersectionObserver === 'undefined') {
        return null;
    }

    if (sharedObserver !== null) {
        return sharedObserver;
    }

    sharedObserver = new IntersectionObserver(
        (entries) => {
            entries.forEach((entry) => {
                // Unit doubles sometimes deliver entries without a target;
                // only real elements can be revealed. An element already
                // above the viewport was jumped past (deep link, fast
                // fling): it is revealed too, so it is never found hidden
                // on the way back up.
                if (!(entry.target instanceof Element)) {
                    return;
                }

                if (
                    entry.isIntersecting ||
                    (entry.boundingClientRect?.bottom ?? 0) < 0
                ) {
                    reveal(entry.target);
                }
            });
        },
        // Threshold zero: a tall element (the SBC grid on a phone runs to
        // thousands of pixels) must reveal as soon as its top edge enters,
        // not once a fraction of its whole height is on screen.
        { threshold: 0, rootMargin: '0px 0px -10% 0px' },
    );

    return sharedObserver;
}

function pruneDetached() {
    observedElements.forEach((element) => {
        if (!element.isConnected) {
            sharedObserver?.unobserve(element);
            observedElements.delete(element);
        }
    });
}

/**
 * Marks the shell reveal-ready (so hidden-until-revealed styles only apply
 * once JS runs) and observes every unrevealed `[data-reveal]` node. Safe to
 * call repeatedly: revealed nodes are skipped, observed nodes are tracked and
 * nodes that left the document are released.
 */
export function scanScrollReveal(root: ParentNode = document) {
    const shell =
        root instanceof Element && root.classList.contains('store-shell')
            ? root
            : root.querySelector('.store-shell');

    shell?.classList.add('store-shell--reveal-ready');

    if (prefersReducedMotion()) {
        revealImmediately(root);

        return;
    }

    const revealObserver = observer();

    if (revealObserver === null) {
        revealImmediately(root);

        return;
    }

    pruneDetached();

    root.querySelectorAll('[data-reveal]:not(.is-revealed)').forEach(
        (element) => {
            const delay = element.getAttribute('data-reveal-delay');

            if (
                delay !== null &&
                delay !== '' &&
                element instanceof HTMLElement
            ) {
                element.style.setProperty('--reveal-delay', `${delay}ms`);
            }

            if (!observedElements.has(element)) {
                observedElements.add(element);
                revealObserver.observe(element);
            }
        },
    );
}

/**
 * Sweeps the observed set on scroll: an element the visitor flung past
 * between two frames never intersected, so the observer never fired for it.
 */
function revealScrolledPast() {
    observedElements.forEach((element) => {
        if (element.getBoundingClientRect().bottom < 0) {
            reveal(element);
        }
    });
}

/**
 * Mounts the shared scroll-reveal observer once per layout. New `[data-reveal]`
 * nodes are picked up as they mount (a MutationObserver on the shell), which
 * covers Inertia navigations, `replace` visits that fire no navigate event,
 * and the catalog grid remounting after an empty result.
 */
export function useScrollReveal() {
    useEffect(() => {
        scanScrollReveal(document);

        const shell = document.querySelector('.store-shell');
        let frame: number | null = null;
        const scheduleScan = () => {
            if (frame !== null) {
                return;
            }

            frame = window.requestAnimationFrame(() => {
                frame = null;
                scanScrollReveal(document);
            });
        };

        let mutations: MutationObserver | null = null;

        if (shell !== null && typeof MutationObserver !== 'undefined') {
            mutations = new MutationObserver(scheduleScan);
            mutations.observe(shell, { childList: true, subtree: true });
        }

        let scrollFrame: number | null = null;
        const onScroll = () => {
            if (scrollFrame !== null || observedElements.size === 0) {
                return;
            }

            scrollFrame = window.requestAnimationFrame(() => {
                scrollFrame = null;
                revealScrolledPast();
            });
        };

        window.addEventListener('scroll', onScroll, { passive: true });

        return () => {
            mutations?.disconnect();
            window.removeEventListener('scroll', onScroll);

            if (frame !== null) {
                window.cancelAnimationFrame(frame);
            }

            if (scrollFrame !== null) {
                window.cancelAnimationFrame(scrollFrame);
            }
        };
    }, []);
}
