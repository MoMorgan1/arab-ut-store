import { config } from '@inertiajs/react';

/**
 * Page transitions between Inertia visits.
 *
 * Without this, a tap on a bottom-bar destination replaces the whole page tree
 * in a single frame: React unmounts the old page — including
 * `AccountMobileBottomNav`, which `MyAccountLayout` renders per page rather than
 * keeping mounted — and mounts the new one, so nothing moves and the change
 * reads as a jump.
 *
 * Inertia hands the swap to the browser's View Transitions API when a visit asks
 * for it: the browser snapshots the outgoing page, React swaps underneath, and
 * the two snapshots are animated. Inertia falls back to the current instant swap
 * in browsers without the API, so this is safe apart from the motion guard.
 *
 * This is switched on through Inertia's global `visitOptions` hook rather than
 * per `<Link>`, so every navigation behaves the same and a page cannot forget to
 * opt in. See the note inside the hook for why it answers `true` even when a
 * visit arrives carrying `false`.
 *
 * The bar keeps its own names (`--arabut-bar-vt` and `--arabut-bar-item-vt` in
 * `app.css`) so the browser treats it as one element rather than cross-fading it
 * with the page, and interpolates the destination that becomes current from its
 * old slot to the new one. That movement is what replaces the jump.
 */

/** Inertia falls back to a plain swap on its own when the API is missing. */
const apiAvailable = (): boolean =>
    typeof document !== 'undefined' &&
    typeof document.startViewTransition === 'function';

const reducedMotionQuery = (): MediaQueryList | null => {
    if (
        typeof window === 'undefined' ||
        typeof window.matchMedia !== 'function'
    ) {
        return null;
    }

    return window.matchMedia('(prefers-reduced-motion: reduce)');
};

/**
 * Motion is a preference, not a default this module may overrule. Read live
 * rather than once at boot: a customer can turn reduced motion on without
 * reloading, and the very next navigation has to respect it.
 */
const prefersReducedMotion = (): boolean =>
    reducedMotionQuery()?.matches ?? false;

/**
 * Prefetches ("hover" and `cacheFor` on the bottom bar) run through the same
 * hook with `prefetch: true` and an explicit `viewTransition: false`. They never
 * swap the DOM, and letting them through would overwrite the option the real
 * visit needs — which is exactly what stopped the first attempt at this from
 * animating anything.
 */
const isPrefetch = (options: Record<string, unknown>): boolean =>
    options.prefetch === true || options.async === true;

export function initializePageTransitions(): void {
    if (!apiAvailable()) {
        return;
    }

    config.set(
        'visitOptions',
        (_href: string, options: Record<string, unknown>) => {
            if (prefersReducedMotion() || isPrefetch(options)) {
                return {};
            }

            /**
             * `true` unconditionally, including over an explicit `false`.
             *
             * That looks wrong, so here is why it is not. The bottom bar's links
             * carry `prefetch="hover"` and `cacheFor="1m"`, and Inertia stores the
             * resolved visit options with the prefetched page. When the customer
             * then taps, those cached options are merged in and arrive here already
             * carrying `viewTransition: false` — the prefetch's own value, not the
             * page's decision. Respecting it meant the transition never ran on any
             * prefetched destination, which is all four of them.
             *
             * Nothing in this codebase opts a navigation out of a transition today,
             * so returning `true` here cannot overrule a real request. If one ever
             * needs to, it should be expressed as an opt-out this hook can see (a
             * per-visit flag that survives the merge), not as the absence of a
             * value that a prefetch has already filled in.
             */
            return { viewTransition: true };
        },
    );
}
