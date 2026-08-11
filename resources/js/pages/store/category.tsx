import { Head, router, usePage } from '@inertiajs/react';
import { Headphones, LockKeyhole, ShieldCheck, Zap } from 'lucide-react';
import { useState } from 'react';

import { CatalogAddControl } from '@/components/store/catalog/catalog-add-control';
import StoreLayout from '@/layouts/store-layout';
import type { CatalogCartSuccess } from '@/lib/catalog-cart-api';
import { formatMinorUnits } from '@/lib/money';
import type {
    CatalogProduct,
    StoreCategoryPageProps,
} from '@/types/store-content';

export default function StoreCategory() {
    const page = usePage<StoreCategoryPageProps>();
    const props = page.props;
    const [query, setQuery] = useState(props.catalog.query);
    const isSbc = props.catalog.service === 'sbc';
    const pageTitle =
        isSbc && props.servicePage.page_title !== undefined
            ? props.servicePage.page_title
            : props.servicePage.title;
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
            <Head title={pageTitle} />
            <section
                aria-labelledby="store-catalog-title"
                className={[
                    'store-catalog-page',
                    isSbc ? 'store-catalog-page--sbc' : null,
                ]
                    .filter(Boolean)
                    .join(' ')}
            >
                <header className="store-catalog-hero">
                    {isSbc ? (
                        <span
                            aria-hidden="true"
                            className="store-catalog-hero__shield"
                        >
                            <img
                                alt=""
                                height="96"
                                src="/images/store/navigation/logo-sbc-96.webp"
                                width="96"
                            />
                        </span>
                    ) : null}
                    <div>
                        <p>{props.servicePage.eyebrow}</p>
                        <h1 id="store-catalog-title">
                            {isSbc ? (
                                <SbcTitle title={pageTitle} />
                            ) : (
                                props.servicePage.title
                            )}
                        </h1>
                        <span>{props.servicePage.intro}</span>
                    </div>
                </header>

                <form
                    action={props.catalogPageUrl}
                    className={[
                        'store-catalog-toolbar',
                        isSbc ? 'store-catalog-toolbar--compact' : null,
                    ]
                        .filter(Boolean)
                        .join(' ')}
                    method="get"
                    onSubmit={(event) => {
                        event.preventDefault();
                        navigate({});
                    }}
                    role="search"
                >
                    {isSbc ? (
                        <h2 className="store-catalog-toolbar__title">
                            {props.catalogPage.browse_by_type}
                        </h2>
                    ) : null}
                    <label className="store-catalog-toolbar__search">
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
                    <fieldset className="store-catalog-toolbar__filters">
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
                    <label className="store-catalog-toolbar__sort">
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
                    <button
                        className="store-catalog-toolbar__submit"
                        type="submit"
                    >
                        {props.catalogPage.search}
                    </button>
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
                                isSbc={isSbc}
                                key={product.id}
                                locale={props.locale}
                                onSuccess={(result) =>
                                    window.dispatchEvent(
                                        new CustomEvent<number>(
                                            'arabut:cart-count',
                                            { detail: result.cartCount },
                                        ),
                                    )
                                }
                                product={product}
                                translations={props.catalogPage}
                            />
                        ))}
                    </ul>
                )}

                {isSbc ? <Assurances translations={props.catalogPage} /> : null}
            </section>
        </StoreLayout>
    );
}

function CatalogCard({
    addUrl,
    isSbc,
    locale,
    onSuccess,
    product,
    translations,
}: {
    addUrl: string;
    isSbc: boolean;
    locale: 'ar' | 'en';
    onSuccess: (result: CatalogCartSuccess) => void;
    product: CatalogProduct;
    translations: StoreCategoryPageProps['catalogPage'];
}) {
    const [variantId, setVariantId] = useState(product.variants[0]?.id ?? '');
    const selected = product.variants.find(
        (variant) => variant.id === variantId,
    );

    return (
        <li
            className={[
                'store-catalog-card',
                isSbc ? 'store-catalog-card--sbc' : null,
            ]
                .filter(Boolean)
                .join(' ')}
        >
            {isSbc ? (
                <span className="store-catalog-card__ribbon">
                    {translations.included}
                </span>
            ) : null}
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
                        (isSbc
                            ? '/images/store/navigation/logo-sbc-96.webp'
                            : '/images/store/hero/arabut-logo-hero.webp')
                    }
                    width="320"
                />
            </a>
            <div className="store-catalog-card__body">
                <h2>{product.name}</h2>
                <p>{product.description}</p>
                {isSbc ? (
                    <ul
                        aria-label={translations.platform_prices}
                        className="store-catalog-card__prices"
                    >
                        {product.variants.map((variant) => (
                            <li key={variant.id}>
                                <button
                                    aria-pressed={variant.id === variantId}
                                    onClick={() => setVariantId(variant.id)}
                                    type="button"
                                >
                                    <PlatformMark
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
                                    </span>
                                </button>
                            </li>
                        ))}
                    </ul>
                ) : (
                    <strong>
                        {selected?.price === null ||
                        selected?.price === undefined
                            ? translations.unavailable_price
                            : formatMinorUnits(
                                  selected.price.amountMinor,
                                  selected.price.currency,
                                  locale,
                              )}
                    </strong>
                )}
                {!isSbc && product.variants.length > 1 ? (
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
                        successLabel={translations.added}
                        variantId={variantId}
                    />
                )}
            </div>
        </li>
    );
}

function SbcTitle({ title }: { title: string }) {
    const suffix = 'SBC';
    const copy = title.endsWith(suffix)
        ? title.slice(0, -suffix.length).trimEnd()
        : title;

    return (
        <>
            {copy} <span className="store-catalog-hero__accent">{suffix}</span>
        </>
    );
}

function PlatformMark({ name, platform }: { name: string; platform: string }) {
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
            <span aria-hidden="true">
                {iconUrls.map((url) => (
                    <img alt="" height="18" key={url} src={url} width="18" />
                ))}
            </span>
            <span>{name}</span>
        </span>
    );
}

function Assurances({
    translations,
}: {
    translations: StoreCategoryPageProps['catalogPage'];
}) {
    const items = [
        [ShieldCheck, translations.assurance_no_players],
        [Zap, translations.assurance_fast],
        [Headphones, translations.assurance_support],
        [LockKeyhole, translations.assurance_secure],
    ] as const;

    return (
        <ul
            aria-label={translations.assurances}
            className="store-catalog-assurances"
        >
            {items.map(([Icon, label]) => (
                <li key={label}>
                    <Icon aria-hidden="true" />
                    <strong>{label}</strong>
                </li>
            ))}
        </ul>
    );
}
