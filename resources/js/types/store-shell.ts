export type StoreLocale = 'ar' | 'en';

export type SimpleStorePageKey =
    'privacy' | 'returns' | 'warranty' | 'ea_backup_codes' | 'terms';

export type StoreShellConfig = {
    homeUrl: string;
    coinsUrl: string;
    cartUrl: string;
    sbcUrl: string;
    futChampionsUrl: string;
    accountUrl: string;
    privacyUrl: string;
    returnsUrl: string;
    warrantyUrl: string;
    eaBackupCodesUrl: string;
    termsUrl: string;
    whatsappUrl: string;
    email: string;
    socials: { x: string; instagram: string };
    payments: Array<{
        name: string;
        imageUrl: string;
        width: number;
        height: number;
    }>;
};

export type StoreShellTranslations = {
    brand: string;
    cart_added: {
        title: string;
        message: string;
        buy_now: string;
        continue_shopping: string;
    };
    language: string;
    currency_selector: string;
    home_title: string;
    skip_to_content: string;
    store_tools: string;
    header: {
        primary_navigation: string;
        preferences: string;
        home: string;
        coins: string;
        sbc: string;
        fut_champions: string;
        most_requested: string;
        whatsapp: string;
        cart: string;
        account: string;
    };
    preferences: {
        exchange_rate_attribution: string;
    };
    footer: {
        description: string;
        important_links: string;
        privacy: string;
        returns: string;
        warranty: string;
        ea_backup_codes: string;
        terms: string;
        customer_service: string;
        whatsapp: string;
        payment_methods: string;
        copyright: string;
        ea_disclaimer: string;
        verified_freelance?: string;
    };
};

export type SimpleStorePageProps = {
    cartCount: number;
    direction: 'rtl' | 'ltr';
    displayCurrency: string;
    displayCurrencies: string[];
    locale: StoreLocale;
    page: StoreInformationPage;
    storeShell: StoreShellConfig;
    ui: StoreShellTranslations;
};

export type StorePageInlineContent = {
    strong?: boolean;
    text: string;
    url?: string;
};

export type StorePageBlock =
    | {
          content: StorePageInlineContent[];
          type: 'paragraph';
      }
    | {
          level: 2 | 3;
          text: string;
          type: 'heading';
      }
    | {
          items: StorePageInlineContent[][];
          ordered: boolean;
          type: 'list';
      }
    | {
          content: StorePageInlineContent[];
          tone: 'info' | 'shield' | 'warning';
          type: 'notice';
      }
    | { type: 'divider' };

export type StoreInformationPage = {
    blocks: StorePageBlock[];
    breadcrumb: { current: string; home: string; label: string };
    key: SimpleStorePageKey;
    subtitle: string | null;
    support: {
        action: string;
        subtitle: string;
        title: string;
        url: string;
    };
    title: string;
    updated: { label: string; value: string };
};

export type StoreCartConfiguration = Partial<{
    completion_count: number;
    coins_quantity: number;
    delivery: 'normal' | 'fast' | null;
    market: 'console' | 'pc';
    matches_played: number;
    pc_launcher: 'ea_app' | 'steam';
    platform: 'playstation' | 'pc';
    price_version: number;
    quoted_at: string;
    schedule_version: number;
    service_type: 'coins' | 'sbc' | 'objectives' | 'rivals' | 'fut_champions';
    target_rank: number;
    urgent: boolean;
    from_division: '7' | '6' | '5' | '4' | '3' | '2' | '1' | 'elite';
    to_division: '7' | '6' | '5' | '4' | '3' | '2' | '1' | 'elite';
    weekly_matches: true;
    included_wins: number;
}>;

export type StoreCartItem = {
    configuration: StoreCartConfiguration;
    credentials: {
        backupCodeCount: 3;
        hasPassword: true;
    } | null;
    credentialsUrl: string | null;
    deleteUrl: string;
    fulfillment?: {
        credentialsReady: boolean;
        squadImagePresent: boolean;
    } | null;
    id: string;
    product: {
        imageUrl: string | null;
        name: string;
        serviceType:
            'coins' | 'sbc' | 'objectives' | 'rivals' | 'fut_champions';
    };
    promotion: {
        badge: string;
        discountHalalah: number;
    } | null;
    quantity: number;
    requiresCredentials: boolean;
    totalHalalah: number;
    unitPriceHalalah: number;
};

export type StoreCartTranslations = {
    title: string;
    eyebrow: string;
    empty: string;
    empty_title: string;
    empty_description: string;
    browse_coins: string;
    items_heading: string;
    summary_title: string;
    checkout_progress: string;
    step_cart: string;
    step_payment: string;
    service: string;
    coins_service: string;
    platform: string;
    platform_playstation: string;
    platform_playstation_manual: string;
    platform_pc: string;
    launcher: string;
    launcher_ea_app: string;
    launcher_steam: string;
    delivery: string;
    delivery_normal: string;
    delivery_fast: string;
    delivery_pc: string;
    quantity: string;
    completions: string;
    rank: string;
    rank_value: string;
    urgent: string;
    urgent_yes: string;
    urgent_no: string;
    matches_played: string;
    from_division: string;
    to_division: string;
    mode: string;
    mode_weekly: string;
    included_wins: string;
    division_elite: string;
    coins_unit: string;
    total: string;
    credentials: string;
    credentials_ready: string;
    credentials_missing: string;
    account_details_ready: string;
    squad_image_ready: string;
    fulfillment_missing: string;
    backup_codes: string;
    backup_code: string;
    ea_email: string;
    ea_password: string;
    current_balance: string;
    credentials_show: string;
    credentials_hide: string;
    edit_credentials: string;
    save_credentials: string;
    cancel_edit: string;
    credentials_saved: string;
    credentials_load_error: string;
    credentials_save_error: string;
    remove_item: string;
    remove_confirm: string;
    remove_cancel: string;
    remove_error: string;
    checkout: string;
    checkout_loading: string;
    checkout_login: string;
    checkout_phone: string;
    checkout_error: string;
    checkout_cart_changed: string;
    phone_country: string;
    phone_number: string;
    phone_code: string;
    phone_send: string;
    phone_sending: string;
    phone_verify: string;
    phone_verifying: string;
    phone_sent: string;
    phone_invalid: string;
    phone_unavailable: string;
    phone_delivery_error: string;
    order_total: string;
    coupon_label: string;
    coupon_placeholder: string;
    coupon_apply: string;
    coupon_applying: string;
    coupon_remove: string;
    coupon_removing: string;
    coupon_applied: string;
    coupon_discount: string;
    coupon_invalid: string;
    coupon_expired: string;
    coupon_limit: string;
    coupon_minimum: string;
    coupon_error: string;
    wallet_toggle: string;
    wallet_deduction: string;
};

export type StoredCartCoupon = {
    code: string;
    discountType: 'percent' | 'fixed';
    discountHalalah: number;
};

export type StoreCartPageProps = {
    auth: { user: { id: number; name: string } | null };
    cartCount: number;
    cart: {
        count: number;
        currency: 'SAR';
        items: StoreCartItem[];
        coupon: StoredCartCoupon | null;
        useWallet: boolean;
    };
    cartPage: {
        checkout: {
            canCheckout: boolean;
            checkoutUrl: string;
            couponApplyUrl: string;
            couponRemoveUrl: string;
            walletToggleUrl: string;
            walletBalanceHalalah: number;
            loginUrl: string;
            phoneCodeUrl: string;
            phoneVerified: boolean;
            phoneVerifyUrl: string;
        };
        translations: StoreCartTranslations;
    };
    direction: 'rtl' | 'ltr';
    displayCurrency: string;
    displayCurrencies: string[];
    locale: StoreLocale;
    storeShell: StoreShellConfig;
    ui: StoreShellTranslations;
};
