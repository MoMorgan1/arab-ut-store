import { useCallback, useEffect, useRef, useState } from 'react';

export type LensPlacement = { left: number; width: number };

/**
 * Where each bar's lens was last resting, held outside React and keyed by the
 * bar's own id.
 *
 * Inertia rebuilds a bar on every page swap, so the element that should be
 * sliding is thrown away and a new one appears already at its destination —
 * the lens teleports instead of travelling, and on a first paint it animates
 * in alongside the page rather than settling onto the section that was just
 * opened. Remembering the last placement lets the replacement start where the
 * old one stopped and travel from there.
 */
const carried = new Map<string, LensPlacement & { key: string }>();

/**
 * Runs `job` once the current placement has had a chance to be painted.
 *
 * `requestAnimationFrame` is the right signal, but it never fires while the
 * tab is hidden — and a bar that only places its lens when visible is a bar
 * with no lens at all. The timeout is the floor that makes sure the placement
 * lands either way; whichever arrives first wins.
 */
export function afterPaint(job: () => void): () => void {
    let ran = false;
    const once = () => {
        if (!ran) {
            ran = true;
            job();
        }
    };

    const frame = requestAnimationFrame(once);
    const timer = window.setTimeout(once, 32);

    return () => {
        cancelAnimationFrame(frame);
        window.clearTimeout(timer);
    };
}

export function prefersReducedMotion(): boolean {
    return (
        typeof window.matchMedia === 'function' &&
        window.matchMedia('(prefers-reduced-motion: reduce)').matches
    );
}

/**
 * One lens that slides between the items of a bar, measured against the
 * active item rather than drawn on each one — so moving between two sections
 * is a single transition instead of one highlight switching off and another
 * switching on.
 *
 * The container is whatever element the items are positioned against, and
 * each item carries `data-lens-key`. Measurement uses `offsetLeft`, which is
 * physical, so a bar that scrolls sideways carries its lens with it and a
 * right-to-left bar needs no special case.
 */
export function useTravellingLens<Container extends HTMLElement>(
    barId: string,
    activeKey: string | null,
    onTravel?: (distance: number) => void,
): {
    container: React.RefObject<Container | null>;
    lens: LensPlacement | null;
    place: () => void;
} {
    const container = useRef<Container | null>(null);
    const [lens, setLens] = useState<LensPlacement | null>(() => {
        const placed = carried.get(barId);

        return placed === undefined
            ? null
            : { left: placed.left, width: placed.width };
    });

    const place = useCallback(() => {
        const host = container.current;

        if (host === null || activeKey === null) {
            setLens(null);

            return;
        }

        const target = host.querySelector<HTMLElement>(
            `[data-lens-key="${activeKey}"]`,
        );

        if (target === null) {
            setLens(null);

            return;
        }

        const next = { left: target.offsetLeft, width: target.offsetWidth };
        const placed = carried.get(barId);

        // Moving to a different item, rather than being re-measured after a
        // rotation or a font swap, is what earns the travel.
        if (
            onTravel !== undefined &&
            placed !== undefined &&
            placed.key !== activeKey
        ) {
            onTravel(Math.abs(next.left - placed.left));
        }

        carried.set(barId, { key: activeKey, ...next });
        setLens(next);
    }, [activeKey, barId, onTravel]);

    useEffect(() => {
        // A beat late on purpose: the carried position has to be painted once
        // before it changes, or there is nothing for the transition to run
        // from and the lens arrives without having travelled.
        const cancel = afterPaint(place);
        const host = container.current;
        const observer = new ResizeObserver(place);

        if (host !== null) {
            observer.observe(host);
        }

        return () => {
            cancel();
            observer.disconnect();
        };
    }, [place]);

    return { container, lens, place };
}
