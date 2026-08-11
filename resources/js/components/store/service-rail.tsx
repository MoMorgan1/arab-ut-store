import { ChevronLeft, ChevronRight } from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';

import type {
    HomeServiceCard,
    ServiceRailTranslations,
} from '@/types/store-content';

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
    const [overflows, setOverflows] = useState(false);
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

    const move = (forward: boolean) => {
        const track = trackRef.current;

        if (track === null) {
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
    };

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

                <div className="store-services-rail">
                    <ul
                        className="store-services-rail__track"
                        dir={direction}
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
                                            height="128"
                                            loading="lazy"
                                            src={service.imageUrl}
                                            width="128"
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
