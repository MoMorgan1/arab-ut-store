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
    platform: 'playstation' | 'pc';
    price_version: number;
    quoted_at: string;
    service_type: 'coins' | 'sbc' | 'objectives' | 'rivals' | 'fut_champions';
}>;

export type StoreCartItem = {
    configuration: StoreCartConfiguration;
    credentials: {
        backupCodeCount: 3;
        hasPassword: true;
    } | null;
    credentialsUrl: string;
    deleteUrl: string;
    id: string;
    product: {
        imageUrl: string | null;
        name: string;
        serviceType:
            'coins' | 'sbc' | 'objectives' | 'rivals' | 'fut_champions';
    };
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
    browse_sbc: string;
    items_heading: string;
    summary_title: string;
    checkout_progress: string;
    step_cart: string;
    step_payment: string;
    service: string;
    coins_service: string;
    platform: string;
    platform_playstation: string;
    platform_pc: string;
    delivery: string;
    delivery_normal: string;
    delivery_fast: string;
    delivery_pc: string;
    quantity: string;
    completions: string;
    coins_unit: string;
    total: string;
    credentials: string;
    credentials_ready: string;
    credentials_missing: string;
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
};

export type StoreCartPageProps = {
    auth: { user: { id: number; name: string } | null };
    cartCount: number;
    cart: { count: number; currency: 'SAR'; items: StoreCartItem[] };
    cartPage: {
        checkout: {
            canCheckout: boolean;
            checkoutUrl: string;
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
