import { usePage } from '@inertiajs/react';
import { useState } from 'react';

import { CatalogAddControl } from '@/components/store/catalog/catalog-add-control';
import { SbcCatalogCard } from '@/components/store/catalog/sbc-catalog-card';
import { SbcProductConfigurator } from '@/components/store/catalog/sbc-product-configurator';
import { StoreSeoHead } from '@/components/store/store-seo-head';
import StoreLayout from '@/layouts/store-layout';
import { formatMinorUnits } from '@/lib/money';
import type { StoreCatalogProductPageProps } from '@/types/store-content';

export default function StoreCatalogProduct() {
    const page = usePage<StoreCatalogProductPageProps>();
    const props = page.props;
    const product = props.catalog.product;
    const isSbc = props.catalog.service === 'sbc';
    const [variantId, setVariantId] = useState(product.variants[0]?.id ?? '');
    const variant = product.variants.find((option) => option.id === variantId);

    return (
        <StoreLayout
            cartCount={props.cartCount}
            currentUrl={page.url}
            direction={props.direction}
            displayCurrencies={props.displayCurrencies}
            displayCurrency={props.displayCurrency}
            locale={props.locale}
            storeShell={props.storeShell}
            ui={props.ui}
        >
            <StoreSeoHead
                title={product.name}
                description={product.description}
                locale={props.locale}
                schemaType="Product"
            />
            <main
                className={[
                    'store-catalog-product',
                    isSbc ? 'store-catalog-product--sbc' : null,
                ]
                    .filter(Boolean)
                    .join(' ')}
                id="store-content"
            >
                <a className="store-catalog-product__back" href={props.backUrl}>
                    {props.productPage.back}
                </a>
                <div className="store-catalog-product__grid">
                    <div className="store-catalog-product__media-column">
                        {isSbc ? (
                            <header className="store-catalog-product__identity">
                                <p>{props.catalog.service.replace('_', ' ')}</p>
                                <h1 id="catalog-product-title">
                                    {product.name}
                                </h1>
                                <p>{product.description}</p>
                            </header>
                        ) : null}
                        <div className="store-catalog-product__image">
                            <img
                                alt={product.image?.alt ?? ''}
                                height="520"
                                src={
                                    product.image?.url ??
                                    '/images/store/hero/arabut-logo-hero.webp'
                                }
                                width="640"
                            />
                        </div>
                    </div>
                    <section
                        aria-labelledby="catalog-product-title"
                        className="store-catalog-product__content"
                    >
                        {!isSbc ? (
                            <>
                                <p>{props.catalog.service.replace('_', ' ')}</p>
                                <h1
                                    className="store-catalog-product__title"
                                    id="catalog-product-title"
                                >
                                    {product.name}
                                </h1>
                                <div className="store-catalog-product__description">
                                    {product.description}
                                </div>
                            </>
                        ) : null}
                        {isSbc ? (
                            <SbcProductConfigurator
                                addUrl={props.sbcCartUrl}
                                currentUrl={page.url}
                                locale={props.locale}
                                product={product}
                                translations={props.productPage}
                            />
                        ) : (
                            <>
                                <label>
                                    <span>
                                        {props.productPage.choose_option}
                                    </span>
                                    <select
                                        aria-label={
                                            props.productPage.choose_option
                                        }
                                        onChange={(event) =>
                                            setVariantId(event.target.value)
                                        }
                                        value={variantId}
                                    >
                                        {product.variants.map((option) => (
                                            <option
                                                key={option.id}
                                                value={option.id}
                                            >
                                                {option.name}
                                            </option>
                                        ))}
                                    </select>
                                </label>
                                <dl>
                                    <div>
                                        <dt>{props.productPage.platform}</dt>
                                        <dd>{variant?.platform ?? '—'}</dd>
                                    </div>
                                    <div>
                                        <dt>{props.productPage.price}</dt>
                                        <dd>
                                            {variant?.price == null
                                                ? props.productPage
                                                      .unavailable_price
                                                : formatMinorUnits(
                                                      variant.price.amountMinor,
                                                      variant.price.currency,
                                                      props.locale,
                                                  )}
                                        </dd>
                                    </div>
                                </dl>
                                {variantId === '' ? null : (
                                    <CatalogAddControl
                                        addUrl={props.catalogCartUrl}
                                        errorLabel={props.productPage.add_error}
                                        idleLabel={
                                            props.productPage.add_to_cart
                                        }
                                        imageAlt={
                                            product.image?.alt || product.name
                                        }
                                        imageUrl={
                                            product.image?.url ??
                                            '/images/store/navigation/logo-sbc-96.webp'
                                        }
                                        loadingLabel={props.productPage.adding}
                                        itemLabel={product.name}
                                        variantId={variantId}
                                    />
                                )}
                            </>
                        )}
                    </section>
                </div>
                {isSbc && props.catalog.suggestions.length > 0 ? (
                    <section
                        aria-labelledby="sbc-related-title"
                        className="store-catalog-related"
                    >
                        <header>
                            <p>{props.productPage.sbc.related_eyebrow}</p>
                            <h2 id="sbc-related-title">
                                {props.productPage.sbc.related_title}
                            </h2>
                        </header>
                        <ul className="store-catalog-related__rail">
                            {props.catalog.suggestions.map((suggestion) => (
                                <SbcCatalogCard
                                    key={suggestion.id}
                                    locale={props.locale}
                                    product={suggestion}
                                    translations={{
                                        included:
                                            props.productPage.sbc
                                                .included_compact,
                                        platform_prices:
                                            props.productPage.sbc
                                                .platform_prices,
                                        unavailable_price:
                                            props.productPage.unavailable_price,
                                    }}
                                />
                            ))}
                        </ul>
                    </section>
                ) : null}
            </main>
        </StoreLayout>
    );
}
