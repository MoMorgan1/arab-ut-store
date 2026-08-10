export type CoinsPlatformValue = 'playstation' | 'pc';

export type CoinsDeliveryValue = 'normal' | 'fast';

export type CoinsAvailability = 'available' | 'unavailable';

export type CoinsCredentials = {
    eaEmail: string;
    eaPassword: string;
    backupCodes: [string, string, string, string, string];
};

export type CoinsCredentialField =
    'email' | 'password' | `code-${0 | 1 | 2 | 3 | 4}`;

export type CoinsResumeSelection = {
    platform: CoinsPlatformValue;
    delivery: CoinsDeliveryValue | null;
    quantity: number;
};

export type CoinsCartConfig = {
    addUrl: string;
    initialSelection: CoinsResumeSelection | null;
    resumeUrl: string;
};

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
        credentials: string;
        summary: string;
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
        badges: Record<CoinsDeliveryValue, string>;
        maximum: string;
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
        normal_delivery_suggestion: string;
        switch_to_fast: string;
    };
    credentials: {
        title: string;
        trust: string;
        email: string;
        password: string;
        show_password: string;
        hide_password: string;
        backup_codes: string;
        backup_code: string;
        backup_help: string;
        required_email: string;
        required_password: string;
        required_code: string;
        duplicate_code: string;
        clear: string;
    };
    summary: {
        title: string;
        service: string;
        service_value: string;
        platform: string;
        delivery: string;
        delivery_pc: string;
        quantity: string;
        total: string;
        credentials_ready: string;
        add: string;
        adding: string;
        retry: string;
        transport_error: string;
        validation_error: string;
        conflict_error: string;
        unavailable_error: string;
        generic_error: string;
    };
    actions: {
        continue: string;
        back: string;
    };
    quote: {
        title: string;
        loading: string;
        refreshing: string;
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
    priceVersion?: number;
    platform: CoinsPlatformValue;
    market: 'console' | 'pc';
    delivery: CoinsDeliveryValue | null;
    quantity: number;
    total: {
        amountHalalah: number;
        currency: 'SAR';
    };
    displayTotal: {
        amountMinor: number;
        currency: string;
    };
    pricedAt: string;
};

export type CoinsQuoteSchedule = {
    delivery: CoinsDeliveryValue | null;
    displayCurrency: string;
    displayTotalsMinor: number[];
    increment: number;
    market: 'console' | 'pc';
    maximum: number;
    minimum: number;
    platform: CoinsPlatformValue;
    pricedAt: string;
    priceVersion: number;
    productId: string;
    totalsHalalah: number[];
    variantId: string;
};

export type CoinsQuoteScheduleKey =
    'playstation:normal' | 'playstation:fast' | 'pc';

export type CoinsQuoteSchedules = Record<
    CoinsQuoteScheduleKey,
    CoinsQuoteSchedule | null
>;

export type CoinsQuoteViewState =
    | { status: 'idle' }
    | { status: 'loading' }
    | { status: 'success'; quote: CoinsQuote }
    | { status: 'refreshing'; quote: CoinsQuote }
    | { status: 'validation' }
    | { status: 'unavailable' };
