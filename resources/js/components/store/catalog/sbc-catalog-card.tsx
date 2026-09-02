import { useRef, useState } from 'react';

import { catalogPlatformName } from '@/lib/catalog-platform-name';
import { formatMinorUnits } from '@/lib/money';
import type {
    CatalogProduct,
    CatalogTranslations,
} from '@/types/store-content';

export function SbcCatalogCard({
    locale,
    product,
    translations,
}: {
    locale: 'ar' | 'en';
    product: CatalogProduct;
    translations: Pick<
        CatalogTranslations,
        'included' | 'platform_prices' | 'unavailable_price'
    >;
}) {
    const [isPressed, setIsPressed] = useState(false);
    const activePen = useRef<{
        pointerId: number;
    } | null>(null);
    const completePress = (pointerId: number) => {
        if (activePen.current?.pointerId !== pointerId) {
            return;
        }

        activePen.current = null;
        setIsPressed(false);
    };
    const resetTilt = (card: HTMLElement) => {
        card.style.setProperty('--sbc-tilt-x', '0deg');
        card.style.setProperty('--sbc-tilt-y', '0deg');
    };

    return (
        <li
            className={[
                'store-catalog-card',
                'store-catalog-card--sbc',
                isPressed ? 'is-pressed' : null,
            ]
                .filter(Boolean)
                .join(' ')}
            onPointerCancel={(event) => {
                if (event.pointerType === 'pen') {
                    completePress(event.pointerId);
                }
            }}
            onPointerDown={(event) => {
                if (event.pointerType !== 'pen') {
                    return;
                }

                activePen.current = {
                    pointerId: event.pointerId,
                };
                setIsPressed(true);
            }}
            onPointerLeave={(event) => {
                if (event.pointerType === 'mouse') {
                    resetTilt(event.currentTarget);

                    return;
                }
            }}
            onPointerMove={(event) => {
                if (event.pointerType === 'mouse') {
                    const bounds = event.currentTarget.getBoundingClientRect();
                    const horizontal =
                        (event.clientX - bounds.left) / bounds.width - 0.5;
                    const vertical =
                        (event.clientY - bounds.top) / bounds.height - 0.5;

                    event.currentTarget.style.setProperty(
                        '--sbc-tilt-x',
                        `${(-vertical * 12).toFixed(1)}deg`,
                    );
                    event.currentTarget.style.setProperty(
                        '--sbc-tilt-y',
                        `${(horizontal * 12).toFixed(1)}deg`,
                    );

                    return;
                }
            }}
            onPointerUp={(event) => {
                if (event.pointerType === 'pen') {
                    completePress(event.pointerId);
                }
            }}
            onTouchCancel={() => setIsPressed(false)}
            onTouchEnd={() => setIsPressed(false)}
            onTouchStart={() => setIsPressed(true)}
        >
            <a
                aria-label={product.name}
                className="store-catalog-card__target"
                href={product.url ?? undefined}
            >
                <span
                    aria-hidden="true"
                    className="store-catalog-card__shine-clip"
                />
                <div className="store-catalog-card__media">
                    <span
                        aria-hidden="true"
                        className="store-catalog-card__artwork-glow"
                    />
                    <span className="store-catalog-card__image">
                        <img
                            alt={
                                product.image === null
                                    ? ''
                                    : product.image.alt || product.name
                            }
                            height="288"
                            draggable={false}
                            loading="lazy"
                            src={
                                product.image?.url ??
                                '/images/store/navigation/logo-sbc-256.webp'
                            }
                            width="384"
                        />
                    </span>
                </div>
                <div className="store-catalog-card__body">
                    <span className="store-catalog-card__included">
                        {translations.included}
                    </span>
                    {product.promotionBadge ? (
                        <span className="store-promo-badge">
                            {product.promotionBadge}
                        </span>
                    ) : null}
                    <h2>{product.name}</h2>
                    <ul
                        aria-label={translations.platform_prices}
                        className="store-catalog-card__prices"
                    >
                        {product.variants.map((variant) => (
                            <li key={variant.id}>
                                <PlatformMark
                                    locale={locale}
                                    name={variant.name}
                                    platform={variant.platform}
                                />
                                <span>
                                    {variant.price === null
                                        ? translations.unavailable_price
                                        : formatMinorUnits(
                                              variant.price.amountMinor,
                                              variant.price.currency,
                                              locale,
                                          )}
                                    {variant.compareAtPrice ? (
                                        <del className="store-price-compare">
                                            {formatMinorUnits(
                                                variant.compareAtPrice
                                                    .amountMinor,
                                                variant.compareAtPrice.currency,
                                                locale,
                                            )}
                                        </del>
                                    ) : null}
                                </span>
                            </li>
                        ))}
                    </ul>
                </div>
            </a>
        </li>
    );
}

function PlatformMark({
    locale,
    name,
    platform,
}: {
    locale: 'ar' | 'en';
    name: string;
    platform: string;
}) {
    const iconUrls =
        platform === 'playstation'
            ? [
                  '/images/store/platforms/ps-logo-white-80.webp',
                  '/images/store/platforms/xbox-logo-white-80.webp',
              ]
            : platform === 'xbox'
              ? ['/images/store/platforms/xbox-logo-white-80.webp']
              : platform === 'pc'
                ? ['/images/store/platforms/pc-logo.svg']
                : [];

    return (
        <span className="store-catalog-card__platform">
            <span
                aria-hidden="true"
                className="store-catalog-card__platform-logos"
            >
                {iconUrls.map((url) => (
                    <img alt="" height="18" key={url} src={url} width="18" />
                ))}
            </span>
            <span className="store-catalog-card__platform-name">
                {catalogPlatformName(platform, name, locale)}
            </span>
        </span>
    );
}
