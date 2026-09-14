import type { JSX } from 'react';
import { useEffect, useRef, useState } from 'react';
import { flushSync } from 'react-dom';

import { cn } from '@/lib/utils';

interface InterruptedState {
    filter: string;
    opacity: number;
    transform: string;
}

function checkCanAnimate(): boolean {
    if (typeof window === 'undefined' || typeof Element === 'undefined') {
        return false;
    }

    if (typeof Element.prototype.animate !== 'function') {
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

function parseDuration(val: string): number {
    const trimmed = val.trim();

    if (!trimmed) {
        return 150;
    }

    if (trimmed.endsWith('ms')) {
        const ms = Number.parseFloat(trimmed);

        return Number.isFinite(ms) ? ms : 150;
    }

    if (trimmed.endsWith('s')) {
        const s = Number.parseFloat(trimmed);

        return Number.isFinite(s) ? s * 1000 : 150;
    }

    const num = Number.parseFloat(trimmed);

    return Number.isFinite(num) ? num : 150;
}

function parsePx(val: string, fallback: number): number {
    const num = Number.parseFloat(val.trim());

    return Number.isFinite(num) ? num : fallback;
}

export function SwapText({
    value,
    as = 'span',
    className,
}: {
    value: string;
    as?: 'span' | 'div';
    className?: string;
}): JSX.Element {
    const elementRef = useRef<HTMLElement | null>(null);
    const [displayedText, setDisplayedText] = useState(value);
    const isFirstMountRef = useRef(true);
    const interruptedStateRef = useRef<InterruptedState | null>(null);

    const canAnimate = checkCanAnimate();

    useEffect(() => {
        if (isFirstMountRef.current) {
            isFirstMountRef.current = false;

            return;
        }

        const el = elementRef.current;

        if (!el || !canAnimate) {
            return;
        }

        let startTransform = 'translateY(0px)';
        let startFilter = 'blur(0px)';
        let startOpacity = 1;

        if (interruptedStateRef.current !== null) {
            startTransform = interruptedStateRef.current.transform;
            startFilter = interruptedStateRef.current.filter;
            startOpacity = interruptedStateRef.current.opacity;
            interruptedStateRef.current = null;
        } else {
            const running = el.getAnimations();

            if (running.length > 0) {
                const computed = window.getComputedStyle(el);

                startTransform =
                    computed.transform && computed.transform !== 'none'
                        ? computed.transform
                        : 'translateY(0px)';
                startFilter =
                    computed.filter && computed.filter !== 'none'
                        ? computed.filter
                        : 'blur(0px)';
                const op = Number.parseFloat(computed.opacity);

                startOpacity = Number.isFinite(op) ? op : 1;
                running.forEach((anim) => anim.cancel());
            }
        }

        const rootStyle = window.getComputedStyle(document.documentElement);
        const dur = parseDuration(
            rootStyle.getPropertyValue('--text-swap-dur'),
        );
        const ease =
            rootStyle.getPropertyValue('--text-swap-ease').trim() ||
            'ease-in-out';
        const y = parsePx(
            rootStyle.getPropertyValue('--text-swap-translate-y'),
            4,
        );
        const blur = parsePx(rootStyle.getPropertyValue('--text-swap-blur'), 2);

        void (async () => {
            let anim1: Animation;

            try {
                anim1 = el.animate(
                    [
                        {
                            filter: startFilter,
                            opacity: startOpacity,
                            transform: startTransform,
                        },
                        {
                            filter: `blur(${blur}px)`,
                            opacity: 0,
                            transform: `translateY(-${y}px)`,
                        },
                    ],
                    {
                        duration: dur,
                        easing: ease,
                        fill: 'forwards',
                    },
                );
            } catch {
                setDisplayedText(value);

                return;
            }

            try {
                await anim1.finished;
            } catch {
                return;
            }

            if (!el.isConnected) {
                return;
            }

            flushSync(() => {
                setDisplayedText(value);
            });

            if (!el.isConnected) {
                return;
            }

            let anim2: Animation;

            try {
                anim2 = el.animate(
                    [
                        {
                            filter: `blur(${blur}px)`,
                            opacity: 0,
                            transform: `translateY(${y}px)`,
                        },
                        {
                            filter: 'blur(0px)',
                            opacity: 1,
                            transform: 'translateY(0px)',
                        },
                    ],
                    {
                        duration: dur,
                        easing: ease,
                    },
                );
            } catch {
                anim1.cancel();

                return;
            }

            anim1.cancel();

            try {
                await anim2.finished;
                anim2.cancel();
            } catch {
                return;
            }
        })();

        return () => {
            if (typeof el.animate !== 'function') {
                return;
            }

            const running = el.getAnimations();

            if (running.length > 0) {
                const computed = window.getComputedStyle(el);

                interruptedStateRef.current = {
                    filter:
                        computed.filter && computed.filter !== 'none'
                            ? computed.filter
                            : 'blur(0px)',
                    opacity: Number.parseFloat(computed.opacity) || 1,
                    transform:
                        computed.transform && computed.transform !== 'none'
                            ? computed.transform
                            : 'translateY(0px)',
                };
                running.forEach((anim) => anim.cancel());
            }
        };
    }, [value, canAnimate]);

    const textToRender = canAnimate ? displayedText : value;

    if (as === 'div') {
        return (
            <div
                className={cn('t-text-swap', className)}
                ref={elementRef as React.RefObject<HTMLDivElement>}
            >
                {textToRender}
            </div>
        );
    }

    return (
        <span
            className={cn('t-text-swap', className)}
            ref={elementRef as React.RefObject<HTMLSpanElement>}
        >
            {textToRender}
        </span>
    );
}
