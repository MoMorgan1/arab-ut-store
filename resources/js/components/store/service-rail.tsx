import { ChevronLeft, ChevronRight } from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';

import type {
    HomeServiceCard,
    ServiceRailTranslations,
} from '@/types/store-content';

const AUTO_SCROLL_PIXELS_PER_SECOND = 50;

export function ServiceRail({
    direction,
    services,
    translations,
}: {
    direction: 'rtl' | 'ltr';
    services: HomeServiceCard[];
    translations: ServiceRailTranslations;
}) {
    const trackRef = useRef<HTMLUListElement>(null);
    const autoTravelDirectionRef = useRef<1 | -1>(1);
    const manualScrollRef = useRef(false);
    const manualResumeTimerRef = useRef<number | null>(null);
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
            setManualPaused(false);
        }, 180);
    }, []);

    const beginManualScroll = useCallback(() => {
        manualScrollRef.current = true;
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
                track.scrollTo({
                    behavior: 'auto',
                    left: 0,
                });

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
            if (previousTimestamp !== null) {
                const maximumScroll = track.scrollWidth - track.clientWidth;

                const distanceFromStart = Math.abs(track.scrollLeft);

                if (distanceFromStart >= maximumScroll - 1) {
                    autoTravelDirectionRef.current = -1;
                } else if (distanceFromStart <= 1) {
                    autoTravelDirectionRef.current = 1;
                }

                const elapsed = Math.min(timestamp - previousTimestamp, 50);
                pendingDistance +=
                    AUTO_SCROLL_PIXELS_PER_SECOND * (elapsed / 1_000);
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
    }, [direction, overflows, pageVisible, paused]);

    return (
        <section
            aria-labelledby="store-services-title"
            className="store-services"
            id="services"
        >
            <div className="store-services__inner">
                <header className="store-section-heading store-services__heading">
                    <p>{translations.eyebrow}</p>
                    <h2 id="store-services-title">{translations.title}</h2>
                </header>

                <div
                    className="store-services-rail"
                    onBlurCapture={(event) => {
                        if (
                            !event.currentTarget.contains(
                                event.relatedTarget as Node | null,
                            )
                        ) {
                            setFocusOrHoverPaused(false);
                        }
                    }}
                    onFocusCapture={() => setFocusOrHoverPaused(true)}
                    onPointerEnter={(event) => {
                        if (event.pointerType === 'mouse') {
                            setFocusOrHoverPaused(true);
                        }
                    }}
                    onPointerLeave={(event) => {
                        if (event.pointerType === 'mouse') {
                            setFocusOrHoverPaused(false);
                        }
                    }}
                    onTouchEnd={scheduleManualResume}
                    onTouchStart={beginManualScroll}
                    onWheel={() => {
                        beginManualScroll();
                        scheduleManualResume();
                    }}
                >
                    <ul
                        className="store-services-rail__track"
                        dir={direction}
                        onScroll={() => {
                            if (manualScrollRef.current) {
                                scheduleManualResume();
                            }
                        }}
                        ref={trackRef}
                    >
                        {services.map((service) => (
                            <li data-testid="service-card" key={service.key}>
                                <a
                                    className="store-service-card"
                                    href={service.href}
                                    rel={
                                        service.external
                                            ? 'noreferrer noopener'
                                            : undefined
                                    }
                                    target={
                                        service.external ? '_blank' : undefined
                                    }
                                >
                                    <span className="store-service-card__image">
                                        <img
                                            alt={service.title}
                                            height="706"
                                            loading="lazy"
                                            src={service.imageUrl}
                                            width="1280"
                                        />
                                    </span>
                                    <strong>{service.title}</strong>
                                    <span>{service.description}</span>
                                </a>
                            </li>
                        ))}
                    </ul>

                    {overflows ? (
                        <div className="store-services-rail__controls">
                            <button
                                aria-label={
                                    direction === 'rtl' ? 'التالي' : 'Previous'
                                }
                                onClick={() => move(false)}
                                type="button"
                            >
                                <ChevronLeft aria-hidden="true" />
                            </button>
                            <button
                                aria-label={
                                    direction === 'rtl' ? 'السابق' : 'Next'
                                }
                                onClick={() => move(true)}
                                type="button"
                            >
                                <ChevronRight aria-hidden="true" />
                            </button>
                        </div>
                    ) : null}
                </div>
            </div>
        </section>
    );
}
