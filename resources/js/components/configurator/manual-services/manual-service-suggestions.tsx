import { ArrowUpRight } from 'lucide-react';

import type {
    ManualServiceSuggestion,
    ManualServiceSuggestionTranslations,
} from '@/types/manual-services';

export function ManualServiceSuggestions({
    services,
    translations,
}: {
    services: ManualServiceSuggestion[];
    translations: ManualServiceSuggestionTranslations;
}) {
    return (
        <section
            aria-labelledby="manual-related-services-title"
            className="manual-service-related"
        >
            <header>
                <p>{translations.eyebrow}</p>
                <h2 id="manual-related-services-title">{translations.title}</h2>
            </header>
            <div className="manual-service-related__grid">
                {services.map((service) => (
                    <a
                        className="manual-service-related__card"
                        href={service.href}
                        key={service.key}
                    >
                        <img
                            alt=""
                            height="706"
                            loading="lazy"
                            src={service.imageUrl}
                            width="1280"
                        />
                        <span>
                            <strong>{service.title}</strong>
                            <small>{service.description}</small>
                            <span className="manual-service-related__cta">
                                {translations.open}
                                <ArrowUpRight aria-hidden="true" />
                            </span>
                        </span>
                    </a>
                ))}
            </div>
        </section>
    );
}
