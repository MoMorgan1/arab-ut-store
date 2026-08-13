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
    faq: FaqEntry[];
    faqTranslations: FaqTranslations;
    reviews: ReviewCollection;
    reviewsTranslations: ReviewTranslations;
    reviewsUrl: string;
    services: HomeServiceCard[];
    servicesTranslations: ServiceRailTranslations;
};

export type ReviewItem = {
    body: string | null;
    id: string;
    publishedAt: string | null;
    rating: number;
    reviewerName: string;
    reviewerLocation: string | null;
    verified: boolean;
};

export type ReviewCollection = {
    average: number | null;
    count: number;
    items: ReviewItem[];
};

export type ReviewTranslations = {
    anonymous_customer: string;
    empty: string;
    eyebrow: string;
    next?: string;
    pages?: string;
    previous?: string;
    rating_label: string;
    summary: string;
    title: string;
    verified: string;
    view_all: string;
};

export type FaqEntry = { answer: string; question: string };
export type FaqTranslations = { eyebrow: string; title: string };

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
    added: string;
    adding: string;
    all: string;
    assurance_fast: string;
    assurance_fast_detail: string;
    assurance_no_players: string;
    assurance_no_players_detail: string;
    assurance_secure: string;
    assurance_secure_detail: string;
    assurance_support: string;
    assurance_support_detail: string;
    assurances: string;
    browse_by_type: string;
    empty: string;
    filter: string;
    foundations: string;
    from: string;
    icons: string;
    included: string;
    newest: string;
    next: string;
    page_status: string;
    pagination: string;
    platform: string;
    platform_prices: string;
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
    sbc: {
        backup_code: string;
        backup_codes: string;
        backup_help: string;
        credentials_title: string;
        duplicate_code: string;
        email: string;
        hide_password: string;
        password: string;
        platform_legend: string;
        required_code: string;
        required_email: string;
        required_password: string;
        related_eyebrow: string;
        related_link: string;
        related_title: string;
        selected: string;
        show_password: string;
        success: string;
        total: string;
    };
};

export type StoreBasePageProps = {
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
        filterCounts: Record<
            'all' | 'players' | 'icons' | 'upgrades' | 'foundations',
            number
        >;
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
        page_title?: string;
        title: string;
    };
};

export type StoreCatalogProductPageProps = StoreBasePageProps & {
    backUrl: string;
    catalog: {
        product: CatalogProduct;
        service: string;
        suggestions: CatalogProduct[];
    };
    catalogCartUrl: string;
    productPage: ProductTranslations;
    sbcCartUrl: string;
};

export type StoreReviewsPageProps = StoreBasePageProps & {
    reviews: ReviewCollection & {
        pagination: {
            lastPage: number;
            page: number;
            perPage: number;
            total: number;
        };
    };
    reviewsPage: ReviewTranslations;
};
