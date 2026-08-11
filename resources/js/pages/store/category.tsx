import { Head, router, usePage } from '@inertiajs/react';
import { useState } from 'react';

import { CatalogAddControl } from '@/components/store/catalog/catalog-add-control';
import StoreLayout from '@/layouts/store-layout';
import { formatMinorUnits } from '@/lib/money';
import type {
    CatalogProduct,
    StoreCategoryPageProps,
} from '@/types/store-content';

export default function StoreCategory() {
    const page = usePage<StoreCategoryPageProps>();
    const props = page.props;
    const [query, setQuery] = useState(props.catalog.query);
    const navigate = (changes: Partial<typeof props.catalog.query>) => {
        const next = { ...query, ...changes, page: 1 };

        setQuery(next);
        router.get(
            props.catalogPageUrl,
            { filter: next.filter, q: next.q, sort: next.sort },
            { preserveScroll: true, replace: true },
        );
    };
    const filters =
        props.catalog.service === 'sbc'
            ? ['all', 'players', 'icons', 'upgrades', 'foundations']
            : ['all'];

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
            <Head title={props.servicePage.title} />
            <main className="store-catalog-page" id="store-content">
                <header className="store-catalog-hero">
                    <p>{props.servicePage.eyebrow}</p>
                    <h1>{props.servicePage.title}</h1>
                    <span>{props.servicePage.intro}</span>
                </header>

                <form
                    action={props.catalogPageUrl}
                    className="store-catalog-toolbar"
                    method="get"
                    onSubmit={(event) => {
                        event.preventDefault();
                        navigate({});
                    }}
                    role="search"
                >
                    <label>
                        <span>{props.catalogPage.search}</span>
                        <input
                            name="q"
                            onChange={(event) =>
                                setQuery((current) => ({
                                    ...current,
                                    q: event.target.value,
                                }))
                            }
                            type="search"
                            value={query.q}
                        />
                    </label>
                    <fieldset>
                        <legend>{props.catalogPage.filter}</legend>
                        {filters.map((filter) => (
                            <button
                                aria-pressed={query.filter === filter}
                                key={filter}
                                name="filter"
                                onClick={() => navigate({ filter })}
                                type="button"
                                value={filter}
                            >
                                {
                                    props.catalogPage[
                                        filter as keyof typeof props.catalogPage
                                    ]
                                }
                            </button>
                        ))}
                    </fieldset>
                    <label>
                        <span>{props.catalogPage.sort}</span>
                        <select
                            aria-label={props.catalogPage.sort}
                            name="sort"
                            onChange={(event) =>
                                navigate({ sort: event.target.value })
                            }
                            value={query.sort}
                        >
                            {[
                                'recommended',
                                'newest',
                                'price_asc',
                                'price_desc',
                            ].map((sort) => (
                                <option key={sort} value={sort}>
                                    {
                                        props.catalogPage[
                                            sort as keyof typeof props.catalogPage
                                        ]
                                    }
                                </option>
                            ))}
                        </select>
                    </label>
                    <button type="submit">{props.catalogPage.search}</button>
                </form>

                {props.catalog.products.length === 0 ? (
                    <p className="store-catalog-empty">
                        {props.catalogPage.empty}
                    </p>
                ) : (
                    <ul className="store-catalog-grid">
                        {props.catalog.products.map((product) => (
                            <CatalogCard
                                addUrl={props.catalogCartUrl}
                                key={product.id}
                                locale={props.locale}
                                onSuccess={(cartUrl) => router.visit(cartUrl)}
                                product={product}
                                translations={props.catalogPage}
                            />
                        ))}
                    </ul>
                )}
            </main>
        </StoreLayout>
    );
}

function CatalogCard({
    addUrl,
    locale,
    onSuccess,
    product,
    translations,
}: {
    addUrl: string;
    locale: 'ar' | 'en';
    onSuccess: (url: string) => void;
    product: CatalogProduct;
    translations: StoreCategoryPageProps['catalogPage'];
}) {
    const [variantId, setVariantId] = useState(product.variants[0]?.id ?? '');
    const selected = product.variants.find(
        (variant) => variant.id === variantId,
    );

    return (
        <li className="store-catalog-card">
            <a
                aria-label={product.name}
                className="store-catalog-card__image"
                href={product.url ?? undefined}
            >
                <img
                    alt={product.image?.alt ?? ''}
                    height="240"
                    loading="lazy"
                    src={
                        product.image?.url ??
                        '/images/store/hero/arabut-logo-hero.webp'
                    }
                    width="320"
                />
            </a>
            <div className="store-catalog-card__body">
                <h2>{product.name}</h2>
                <p>{product.description}</p>
                <strong>
                    {selected?.price === null || selected?.price === undefined
                        ? translations.unavailable_price
                        : formatMinorUnits(
                              selected.price.amountMinor,
                              selected.price.currency,
                              locale,
                          )}
                </strong>
                {product.variants.length > 1 ? (
                    <label>
                        <span>{translations.platform}</span>
                        <select
                            onChange={(event) =>
                                setVariantId(event.target.value)
                            }
                            value={variantId}
                        >
                            {product.variants.map((variant) => (
                                <option key={variant.id} value={variant.id}>
                                    {variant.name}
                                </option>
                            ))}
                        </select>
                    </label>
                ) : null}
                {variantId === '' ? null : (
                    <CatalogAddControl
                        addUrl={addUrl}
                        errorLabel={translations.add_error}
                        idleLabel={translations.add_to_cart}
                        loadingLabel={translations.adding}
                        onSuccess={onSuccess}
                        variantId={variantId}
                    />
                )}
            </div>
        </li>
    );
}
