import { ChevronLeft, ChevronRight } from 'lucide-react';

import { useBouncingHorizontalRail } from '@/hooks/use-bouncing-horizontal-rail';
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
    const { containerProps, move, overflows, trackProps } =
        useBouncingHorizontalRail({ direction });

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

                <div className="store-services-rail" {...containerProps}>
                    <ul className="store-services-rail__track" {...trackProps}>
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
