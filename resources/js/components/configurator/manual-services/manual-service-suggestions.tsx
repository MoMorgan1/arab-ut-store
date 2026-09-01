import { ArrowUpRight } from 'lucide-react';

import { formatMinorUnits } from '@/lib/money';
import type {
    ManualServiceCommonTranslations,
    ManualServicePlatform,
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
                <div className="manual-sbc-section">
                    <div className="manual-sbc-rail">
                        <ul className="manual-sbc-grid" role="list">
                            {products.map((product) => {
                                const formattedPrice = product.price
                                    ? formatMinorUnits(
                                          product.price.amountMinor,
                                          product.price.currency,
                                          locale,
                                      )
                                    : null;
                                const formattedCompareAt =
                                    product.compareAtPrice
                                        ? formatMinorUnits(
                                              product.compareAtPrice
                                                  .amountMinor,
                                              product.compareAtPrice.currency,
                                              locale,
                                          )
                                        : null;

                                return (
                                    <li
                                        className="manual-sbc-card"
                                        key={product.id}
                                    >
                                        <a
                                            aria-label={product.name}
                                            className="manual-sbc-card__link"
                                            href={product.url}
                                        >
                                            <div className="manual-sbc-card__media">
                                                <img
                                                    alt={
                                                        product.image?.alt ||
                                                        product.name
                                                    }
                                                    draggable={false}
                                                    height="240"
                                                    loading="lazy"
                                                    src={
                                                        product.image?.url ||
                                                        '/images/store/hero/arabut-logo-hero.webp'
                                                    }
                                                    width="320"
                                                />
                                                {product.promotionBadge ? (
                                                    <span className="store-promo-badge">
                                                        {product.promotionBadge}
                                                    </span>
                                                ) : null}
                                            </div>
                                            <div className="manual-sbc-card__body">
                                                <h3 className="manual-sbc-card__title">
                                                    {product.name}
                                                </h3>
                                                <p className="manual-sbc-card__description">
                                                    {product.description}
                                                </p>
                                                {product.platforms.length >
                                                0 ? (
                                                    <div className="manual-sbc-card__platforms">
                                                        {product.platforms.map(
                                                            (platform) => (
                                                                <span
                                                                    className="manual-sbc-card__platform"
                                                                    key={
                                                                        platform
                                                                    }
                                                                >
                                                                    {common
                                                                        .platforms[
                                                                        platform as ManualServicePlatform
                                                                    ] ??
                                                                        platform}
                                                                </span>
                                                            ),
                                                        )}
                                                    </div>
                                                ) : null}
                                                <div className="manual-sbc-card__pricing">
                                                    {formattedPrice ? (
                                                        <strong className="manual-sbc-card__price">
                                                            {formattedPrice}
                                                        </strong>
                                                    ) : null}
                                                    {formattedCompareAt ? (
                                                        <del className="store-price-compare">
                                                            {formattedCompareAt}
                                                        </del>
                                                    ) : null}
                                                </div>
                                            </div>
                                        </a>
                                    </li>
                                );
                            })}
                        </ul>
                    </div>

                    <div className="manual-sbc-footer">
                        <a
                            className="manual-service-related__cta manual-sbc-see-all"
                            href={sbcUrl}
                        >
                            {common.see_all_sbc}
                            <ArrowUpRight aria-hidden="true" />
                        </a>
                    </div>
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
