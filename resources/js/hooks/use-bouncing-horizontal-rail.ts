import { useCallback, useEffect, useRef, useState } from 'react';
import type { FocusEvent, PointerEvent } from 'react';

const DEFAULT_PIXELS_PER_SECOND = 50;
const MANUAL_RESUME_DELAY_MS = 900;
// After an arrow or dot press the visitor wants to read the card they asked
// for; the glide waits longer before drifting the rail back.
const PROGRAMMATIC_RESUME_DELAY_MS = 4_000;

export function useBouncingHorizontalRail({
    direction,
    pixelsPerSecond = DEFAULT_PIXELS_PER_SECOND,
}: {
    direction: 'rtl' | 'ltr';
    pixelsPerSecond?: number;
}) {
    const trackRef = useRef<HTMLUListElement>(null);
    const autoTravelDirectionRef = useRef<1 | -1>(1);
    const manualScrollRef = useRef(false);
    const manualResumeTimerRef = useRef<number | null>(null);
    const resumeDelayRef = useRef(MANUAL_RESUME_DELAY_MS);
    const [overflows, setOverflows] = useState(false);
    const [focusOrHoverPaused, setFocusOrHoverPaused] = useState(false);
    const [manualPaused, setManualPaused] = useState(false);
    const [pageVisible, setPageVisible] = useState(!document.hidden);
    const paused = focusOrHoverPaused || manualPaused;

    const measure = useCallback(() => {
        const track = trackRef.current;

        setOverflows(
            track !== null && track.scrollWidth > track.clientWidth + 1,
        );
    }, []);

    useEffect(() => {
        measure();

        if (typeof ResizeObserver === 'undefined') {
            return;
        }

        const observer = new ResizeObserver(measure);

        if (trackRef.current !== null) {
            observer.observe(trackRef.current);
        }

        return () => observer.disconnect();
    }, [measure]);

    useEffect(() => {
        const updateVisibility = () => setPageVisible(!document.hidden);

        document.addEventListener('visibilitychange', updateVisibility);

        return () =>
            document.removeEventListener('visibilitychange', updateVisibility);
    }, []);

    const scheduleManualResume = useCallback(() => {
        if (manualResumeTimerRef.current !== null) {
            window.clearTimeout(manualResumeTimerRef.current);
        }

        manualResumeTimerRef.current = window.setTimeout(() => {
            manualScrollRef.current = false;
            manualResumeTimerRef.current = null;
            resumeDelayRef.current = MANUAL_RESUME_DELAY_MS;
            setManualPaused(false);
        }, resumeDelayRef.current);
    }, []);

    const beginManualScroll = useCallback(() => {
        manualScrollRef.current = true;
        resumeDelayRef.current = MANUAL_RESUME_DELAY_MS;
        setManualPaused(true);

        if (manualResumeTimerRef.current !== null) {
            window.clearTimeout(manualResumeTimerRef.current);
            manualResumeTimerRef.current = null;
        }
    }, []);

    useEffect(
        () => () => {
            if (manualResumeTimerRef.current !== null) {
                window.clearTimeout(manualResumeTimerRef.current);
            }
        },
        [],
    );

    const move = useCallback(
        (forward: boolean) => {
            const track = trackRef.current;

            if (track === null) {
                return;
            }

            const reachedEnd =
                Math.abs(track.scrollLeft) + track.clientWidth >=
                track.scrollWidth - 2;

            if (forward && reachedEnd) {
                track.scrollTo({ behavior: 'auto', left: 0 });

                return;
            }

            const logicalDirection = direction === 'rtl' ? -1 : 1;
            track.scrollBy({
                behavior: window.matchMedia('(prefers-reduced-motion: reduce)')
                    .matches
                    ? 'auto'
                    : 'smooth',
                left:
                    logicalDirection *
                    (forward ? 1 : -1) *
                    Math.max(track.clientWidth * 0.82, 280),
            });
        },
        [direction],
    );

    useEffect(() => {
        // Touch screens glide too: a finger on the rail pauses the glide (see
        // the touch handlers below) and it resumes shortly after the momentum
        // scroll settles, so the script never fights the native scroller.
        if (
            !overflows ||
            paused ||
            !pageVisible ||
            window.matchMedia('(prefers-reduced-motion: reduce)').matches
        ) {
            return;
        }

        const track = trackRef.current;

        if (track === null) {
            return;
        }

        const logicalDirection = direction === 'rtl' ? -1 : 1;
        let animationFrame = 0;
        let pendingDistance = 0;
        let previousTimestamp: number | null = null;

        const advance = (timestamp: number) => {
            if (manualScrollRef.current) {
                previousTimestamp = timestamp;
                animationFrame = window.requestAnimationFrame(advance);

                return;
            }

            if (previousTimestamp !== null) {
                const maximumScroll = track.scrollWidth - track.clientWidth;
                const distanceFromStart = Math.abs(track.scrollLeft);

                if (distanceFromStart >= maximumScroll - 1) {
                    autoTravelDirectionRef.current = -1;
                } else if (distanceFromStart <= 1) {
                    autoTravelDirectionRef.current = 1;
                }

                // Whole pixels only: iOS Safari rounds fractional scrollBy
                // deltas, so sub-pixel steps every frame crawled and dropped
                // frames on phones.
                const elapsed = Math.min(timestamp - previousTimestamp, 50);
                pendingDistance += pixelsPerSecond * (elapsed / 1_000);
                const wholePixels = Math.floor(pendingDistance);

                if (wholePixels > 0) {
                    pendingDistance -= wholePixels;
                    track.scrollBy({
                        behavior: 'auto',
                        left:
                            logicalDirection *
                            autoTravelDirectionRef.current *
                            wholePixels,
                    });
                }
            }

            previousTimestamp = timestamp;
            animationFrame = window.requestAnimationFrame(advance);
        };

        animationFrame = window.requestAnimationFrame(advance);

        return () => window.cancelAnimationFrame(animationFrame);
    }, [direction, overflows, pageVisible, paused, pixelsPerSecond]);

    // Arrows and dots scroll the rail themselves; the glide would cancel their
    // smooth scroll every frame, so they pause it the way a drag does.
    const pauseForProgrammaticScroll = useCallback(() => {
        beginManualScroll();
        resumeDelayRef.current = PROGRAMMATIC_RESUME_DELAY_MS;
        scheduleManualResume();
    }, [beginManualScroll, scheduleManualResume]);

    return {
        containerProps: {
            onBlurCapture: (event: FocusEvent<HTMLElement>) => {
                if (
                    !event.currentTarget.contains(
                        event.relatedTarget as Node | null,
                    )
                ) {
                    setFocusOrHoverPaused(false);
                }
            },
            onFocusCapture: () => setFocusOrHoverPaused(true),
            onPointerEnter: (event: PointerEvent<HTMLElement>) => {
                if (event.pointerType === 'mouse') {
                    setFocusOrHoverPaused(true);
                }
            },
            onPointerLeave: (event: PointerEvent<HTMLElement>) => {
                if (event.pointerType === 'mouse') {
                    setFocusOrHoverPaused(false);
                }
            },
            onTouchEnd: scheduleManualResume,
            onTouchStart: beginManualScroll,
            onWheel: () => {
                beginManualScroll();
                scheduleManualResume();
            },
        },
        move,
        overflows,
        pauseForProgrammaticScroll,
        trackProps: {
            dir: direction,
            onScroll: () => {
                if (manualScrollRef.current) {
                    scheduleManualResume();
                }
            },
            ref: trackRef,
        },
    };
}
