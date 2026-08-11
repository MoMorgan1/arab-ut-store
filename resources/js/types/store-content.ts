import type {
    StoreShellConfig,
    StoreShellTranslations,
} from '@/types/store-shell';

export type HomeServiceCard = {
    description: string;
    external: boolean;
    href: string;
    imageUrl: string;
    key: 'sbc' | 'objectives' | 'fut_champions' | 'rivals' | 'sell_coins';
    title: string;
};

export type ServiceRailTranslations = {
    eyebrow: string;
    title: string;
};

export type StoreHomeContent = {
    services: HomeServiceCard[];
    servicesTranslations: ServiceRailTranslations;
};

export type CatalogMoney = { amountMinor: number; currency: string };
export type CatalogVariant = {
    id: string;
    name: string;
    platform: string;
    price: CatalogMoney | null;
};
export type CatalogProduct = {
    description: string;
    id: string;
    image: { alt: string; url: string } | null;
    name: string;
    platforms: string[];
    price: CatalogMoney | null;
    slug: string;
    url: string | null;
    variants: CatalogVariant[];
};

export type CatalogTranslations = {
    add_error: string;
    add_to_cart: string;
    adding: string;
    all: string;
    empty: string;
    filter: string;
    foundations: string;
    from: string;
    icons: string;
    newest: string;
    next: string;
    platform: string;
    players: string;
    previous: string;
    price_asc: string;
    price_desc: string;
    recommended: string;
    search: string;
    sort: string;
    unavailable_price: string;
    upgrades: string;
};

export type ProductTranslations = {
    add_error: string;
    add_to_cart: string;
    adding: string;
    back: string;
    choose_option: string;
    platform: string;
    price: string;
    unavailable_price: string;
};

type StoreBasePageProps = {
    cartCount: number;
    direction: 'rtl' | 'ltr';
    displayCurrencies: string[];
    displayCurrency: string;
    locale: 'ar' | 'en';
    storeShell: StoreShellConfig;
    ui: StoreShellTranslations;
};

export type StoreCategoryPageProps = StoreBasePageProps & {
    catalog: {
        pagination: {
            lastPage: number;
            page: number;
            perPage: number;
            total: number;
        };
        products: CatalogProduct[];
        query: { filter: string; page: number; q: string; sort: string };
        service: string;
    };
    catalogCartUrl: string;
    catalogPage: CatalogTranslations;
    catalogPageUrl: string;
    servicePage: {
        card_description: string;
        eyebrow: string;
        intro: string;
        title: string;
    };
};

export type StoreCatalogProductPageProps = StoreBasePageProps & {
    backUrl: string;
    catalog: { product: CatalogProduct; service: string };
    catalogCartUrl: string;
    productPage: ProductTranslations;
};
