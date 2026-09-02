import { ArrowUpRight } from 'lucide-react';

import { SbcCatalogCard } from '@/components/store/catalog/sbc-catalog-card';
import type {
    ManualServiceCommonTranslations,
    ManualServiceRelatedServices,
    ManualServiceSuggestionTranslations,
} from '@/types/manual-services';

export function ManualServiceSuggestions({
    common,
    locale,
    relatedServices,
    translations,
}: {
    common: ManualServiceCommonTranslations;
    locale: 'ar' | 'en';
    relatedServices: ManualServiceRelatedServices;
    translations: ManualServiceSuggestionTranslations;
}) {
    const { products, sbcUrl, service } = relatedServices;

    return (
        <section
            aria-labelledby="manual-related-services-title"
            className="manual-service-related"
        >
            <header className="manual-service-related__header">
                <p className="manual-service-related__eyebrow">
                    {translations.eyebrow}
                </p>
                <h2
                    className="manual-service-related__title"
                    id="manual-related-services-title"
                >
                    {translations.title}
                </h2>
            </header>

            {products.length > 0 ? (
                <div className="store-catalog-related store-catalog-related--embedded">
                    <ul className="store-catalog-related__rail">
                        {products.map((product) => (
                            <SbcCatalogCard
                                key={product.id}
                                locale={locale}
                                product={product}
                                translations={translations.sbc}
                            />
                        ))}
                    </ul>
                    <a className="manual-service-related__cta" href={sbcUrl}>
                        {common.see_all_sbc}
                        <ArrowUpRight aria-hidden="true" />
                    </a>
                </div>
            ) : null}

            <div className="manual-service-other">
                <a className="manual-service-other__card" href={service.href}>
                    <div className="manual-service-other__media">
                        <img
                            alt=""
                            height="360"
                            loading="lazy"
                            src={service.imageUrl}
                            width="640"
                        />
                    </div>
                    <div className="manual-service-other__body">
                        <strong className="manual-service-other__title">
                            {service.title}
                        </strong>
                        <p className="manual-service-other__description">
                            {service.description}
                        </p>
                        <span className="manual-service-related__cta">
                            {translations.open}
                            <ArrowUpRight aria-hidden="true" />
                        </span>
                    </div>
                </a>
            </div>
        </section>
    );
}
