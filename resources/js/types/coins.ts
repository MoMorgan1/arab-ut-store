export type CoinsPlatformValue = 'playstation' | 'pc';

export type CoinsDeliveryValue = 'normal' | 'fast';

export type CoinsAvailability = 'available' | 'unavailable';

export type CoinsCredentials = {
    eaEmail: string;
    eaPassword: string;
    backupCodes: [string, string, string];
    currentBalance?: string;
    companionMarketOpen?: boolean;
    policyAccepted?: boolean;
};

export type CoinsCredentialField =
    | 'email'
    | 'password'
    | 'current-balance'
    | 'companion'
    | 'policy'
    | `code-${0 | 1 | 2}`;

export type CoinsResumeSelection = {
    platform: CoinsPlatformValue;
    delivery: CoinsDeliveryValue | null;
    quantity: number;
};

export type CoinsCartConfig = {
    addUrl: string;
    initialSelection: CoinsResumeSelection | null;
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

export type CoinsQuantityTier = {
    upTo: number;
    step: number;
};

export type CoinsAmountRules = {
    minimum: number;
    /** The grain a typed quantity rounds to; the bands only move the slider. */
    roundingUnit: number;
    tiers: CoinsQuantityTier[];
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
        services_cta: string;
        proof_label: string;
        stats: Array<{ value: string; unit: string; label: string }>;
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
        current_balance: string;
        current_balance_help: string;
        companion_market_open: string;
        market_guide: string;
        market_open_label: string;
        market_closed_label: string;
        market_modal: {
            close: string;
            badge: string;
            title: string;
            subtitle: string;
            steps: Array<{ title: string; body: string }>;
            open_badge: string;
            open_description: string;
            closed_badge: string;
            closed_description: string;
            note: string;
        };
        policy_agree_prefix: string;
        policy_agree_join: string;
        policy_agree_suffix: string;
        terms_link: string;
        warranty_link: string;
        required_email: string;
        required_password: string;
        required_code: string;
        duplicate_code: string;
        required_balance: string;
        required_companion: string;
        required_policy: string;
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
        in_cart: string;
        open_cart: string;
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
    quantities: number[];
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
