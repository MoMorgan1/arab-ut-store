import { useEffect, useRef } from 'react';
import type { RefObject } from 'react';

function canAnimate(el: HTMLElement | null): el is HTMLElement {
    if (!el) {
        return false;
    }

    if (typeof window === 'undefined' || typeof document === 'undefined') {
        return false;
    }

    if (typeof el.animate !== 'function') {
        return false;
    }

    if (
        typeof window.matchMedia === 'function' &&
        window.matchMedia('(prefers-reduced-motion: reduce)').matches
    ) {
        return false;
    }

    return true;
}

function parseDuration(val: string, fallback: number): number {
    const trimmed = val.trim();

    if (!trimmed) {
        return fallback;
    }

    if (trimmed.endsWith('ms')) {
        const ms = Number.parseFloat(trimmed);

        return Number.isFinite(ms) ? ms : fallback;
    }

    if (trimmed.endsWith('s')) {
        const s = Number.parseFloat(trimmed);

        return Number.isFinite(s) ? s * 1000 : fallback;
    }

    const num = Number.parseFloat(trimmed);

    return Number.isFinite(num) ? num : fallback;
}

function parsePx(val: string, fallback: number): number {
    const trimmed = val.trim();

    if (!trimmed) {
        return fallback;
    }

    const num = Number.parseFloat(trimmed);

    return Number.isFinite(num) ? num : fallback;
}

/** Shake an element once. Safe to call repeatedly: a running shake is cancelled and restarted. */
export function shake(el: HTMLElement | null): void {
    if (!canAnimate(el)) {
        return;
    }

    if (typeof el.getAnimations === 'function') {
        el.getAnimations().forEach((anim) => anim.cancel());
    }

    const rootStyle = window.getComputedStyle(document.documentElement);
    const durA = parseDuration(rootStyle.getPropertyValue('--shake-dur-a'), 80);
    // An unregistered custom property keeps its calc() unevaluated in
    // getComputedStyle, so the fallback for B is derived from A.
    const durB = parseDuration(
        rootStyle.getPropertyValue('--shake-dur-b'),
        durA * 0.75,
    );
    const distance = parsePx(rootStyle.getPropertyValue('--shake-distance'), 8);
    const overshoot = parsePx(
        rootStyle.getPropertyValue('--shake-overshoot'),
        6,
    );
    const ease =
        rootStyle.getPropertyValue('--shake-ease').trim() || 'ease-in-out';

    const total = 2 * durA + 2 * durB;

    if (total <= 0) {
        return;
    }

    try {
        el.animate(
            [
                { offset: 0, transform: 'translateX(0px)', easing: ease },
                {
                    offset: durA / total,
                    transform: `translateX(${distance}px)`,
                    easing: ease,
                },
                {
                    offset: (2 * durA) / total,
                    transform: `translateX(-${distance}px)`,
                    easing: ease,
                },
                {
                    offset: (2 * durA + durB) / total,
                    transform: `translateX(${overshoot}px)`,
                    easing: ease,
                },
                { offset: 1, transform: 'translateX(0px)' },
            ],
            {
                duration: total,
                fill: 'none',
            },
        );
    } catch {
        // Never throw
    }
}

/** Shake `ref` whenever `error` becomes truthy or changes to a different message. */
export function useErrorShake<T extends HTMLElement = HTMLElement>(
    ref: RefObject<T | null>,
    error: string | undefined | null,
): void {
    const prevErrorRef = useRef<string | undefined | null>(undefined);

    useEffect(() => {
        const el = ref.current;
        const isTruthy = Boolean(error);
        const wasTruthy = Boolean(prevErrorRef.current);
        const changed = error !== prevErrorRef.current;

        if (isTruthy && (!wasTruthy || changed)) {
            shake(el);

            if (canAnimate(el)) {
                const tag = el.tagName.toLowerCase();
                const isField =
                    tag === 'input' ||
                    tag === 'textarea' ||
                    tag === 'select' ||
                    tag === 'button';

                if (!isField) {
                    const rootStyle = window.getComputedStyle(
                        document.documentElement,
                    );
                    const revealDur = parseDuration(
                        rootStyle.getPropertyValue('--error-reveal-dur'),
                        150,
                    );
                    const ease =
                        rootStyle.getPropertyValue('--shake-ease').trim() ||
                        'ease-in-out';

                    try {
                        el.animate([{ opacity: 0 }, { opacity: 1 }], {
                            duration: revealDur,
                            easing: ease,
                            fill: 'none',
                        });
                    } catch {
                        // Never throw
                    }
                }
            }
        }

        prevErrorRef.current = error;

        return () => {
            if (el && typeof el.getAnimations === 'function') {
                el.getAnimations().forEach((anim) => anim.cancel());
            }
        };
    }, [ref, error]);
}
