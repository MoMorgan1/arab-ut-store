export type CoinsPlatformValue = 'playstation' | 'pc';

export type CoinsDeliveryValue = 'normal' | 'fast';

export type CoinsAvailability = 'available' | 'unavailable';

export type CoinsDeliveryOption = {
    value: CoinsDeliveryValue;
    label: string;
    maximum: number;
    minutesPerMillion: number;
};

export type CoinsPlatformOption = {
    value: CoinsPlatformValue;
    label: string;
    iconUrls: string[];
    maximum: number;
    deliveries: CoinsDeliveryOption[];
};

export type CoinsAmountRules = {
    minimum: number;
    increment: number;
    presets: number[];
};

export type CoinsProductSummary = {
    publicId: string;
    name: string;
    imageUrl: string;
};

export type CoinsStoreTranslations = {
    seo_title: string;
    hero: {
        badge: string;
        title: string;
        accent: string;
        subtitle: string;
        cta: string;
        proof_label: string;
        stats: Array<{ value: string; label: string }>;
    };
    coins_section: {
        tag: string;
        title: string;
        intro: string;
    };
    availability: {
        title: string;
        body: string;
    };
    progress: {
        platform: string;
        delivery: string;
        amount: string;
    };
    platform: {
        title: string;
        help: string;
        options: Record<CoinsPlatformValue, string>;
        descriptions: Record<CoinsPlatformValue, string>;
    };
    delivery: {
        title: string;
        help: string;
        eta: string;
        options: Record<CoinsDeliveryValue, string>;
    };
    amount_copy: {
        title: string;
        help: string;
        label: string;
        preset_label: string;
        slider_label: string;
        minimum_label: string;
        maximum_label: string;
        clamped: string;
    };
    actions: {
        continue: string;
        back: string;
        restart: string;
    };
    quote: {
        title: string;
        loading: string;
        total: string;
        unavailable: string;
        validation_error: string;
    };
    units: {
        coins: string;
        million: string;
    };
    accessibility: {
        steps: string;
        selection: string;
        live: string;
    };
};

export type CoinsQuote = {
    productId: string;
    variantId: string;
    platform: CoinsPlatformValue;
    market: 'console' | 'pc';
    delivery: CoinsDeliveryValue | null;
    quantity: number;
    total: {
        amountHalalah: number;
        currency: 'SAR';
    };
    pricedAt: string;
};

export type CoinsQuoteViewState =
    | { status: 'idle' }
    | { status: 'loading' }
    | { status: 'success'; quote: CoinsQuote }
    | { status: 'validation' }
    | { status: 'unavailable' };
