import type {
    StoreLocale,
    StoreShellConfig,
    StoreShellTranslations,
} from '@/types/store-shell';

export type AccountDestination =
    'overview' | 'orders' | 'wallet' | 'profile' | 'security' | 'support';

export type AccountMoney = {
    amountMinor: string;
    currency: string;
};

export type AccountOrderStatus =
    | 'pending_payment'
    | 'received'
    | 'in_progress'
    | 'waiting_for_customer'
    | 'completed'
    | 'cancelled'
    | 'refunded'
    | 'failed';

export type AccountOrderAction =
    'view_order' | 'pay_now' | 'retry_payment' | 'provide_details';

export type AccountOrder = {
    id: string;
    source: 'live';
    number: string;
    status: AccountOrderStatus;
    placedAt: string;
    summary: string;
    itemCount: number;
    total: AccountMoney;
    detailUrl: string;
    action?: { type: AccountOrderAction };
};

export type AccountNavigationItem = {
    key: AccountDestination;
    label: string;
    url: string;
};

export type AccountTier = {
    key: string;
    name: string;
    minimum: AccountMoney;
};

export type AccountLoyalty = {
    eligibleSpend: AccountMoney;
    currentTier: AccountTier | null;
    nextTier: AccountTier | null;
    remaining: AccountMoney | null;
    progressPercent: number;
};

export type AccountTranslations = {
    page_title: string;
    eyebrow: string;
    greeting: string;
    introduction: string;
    email_alert?: {
        title: string;
        desc: string;
        action: string;
    };
    navigation: {
        label: string;
        overview: string;
        orders: string;
        wallet: string;
        profile: string;
        security: string;
        support: string;
        logout: string;
        admin?: string;
    };
    bottom_nav?: {
        home: string;
        account: string;
    };
    overview: {
        title: string;
        subtitle?: string;
        description: string;
        orders_metric: string;
        open_orders_metric: string;
        completed_orders_metric: string;
        wallet_metric: string;
        active_order: string;
        current_order?: string;
        recent_orders: string;
        view_all?: string;
        loyalty: string;
        empty_title: string;
        empty_description: string;
        browse_services: string;
        loyalty_remaining: string;
        loyalty_complete: string;
    };
    orders: {
        title: string;
        description: string;
        all: string;
        open: string;
        completed: string;
        empty_title: string;
        empty_description: string;
        number: string;
        placed_at: string;
        total: string;
        status: string;
        source_live: string;
        source_archive: string;
        filters_label: string;
        previous: string;
        next: string;
        pagination: string;
        page_status: string;
        items_title: string;
        item_quantity: string;
        credentials_ready: string;
        manual_details: string;
        platform: string;
        platform_playstation: string;
        platform_pc: string;
        launcher: string;
        launcher_ea_app: string;
        launcher_steam: string;
        rank: string;
        rank_value: string;
        urgent: string;
        urgent_yes: string;
        urgent_no: string;
        matches_played: string;
        from_division: string;
        to_division: string;
        elite: string;
        show_credentials: string;
        hide_credentials: string;
        credentials_loading: string;
        credentials_error: string;
        squad_image: string;
        playstation_email: string;
        playstation_password: string;
        ea_email: string;
        ea_password: string;
        steam_username: string;
        steam_password: string;
        ea_codes: string;
        playstation_codes: string;
        refresh_status: string;
        refreshing: string;
        back: string;
        copy: string;
        copied: string;
    };
    wallet: {
        title: string;
        description: string;
        coming_soon?: string;
        coming_soon_notice?: string;
        page_coming_soon_title?: string;
        page_coming_soon_desc?: string;
        feature_balance?: string;
        feature_refund?: string;
        feature_checkout?: string;
        available_balance: string;
        unavailable_balance: string;
        loyalty_title: string;
        ledger_title: string;
        empty_title: string;
        empty_description: string;
        credit: string;
        debit: string;
        refund: string;
        adjustment: string;
        balance_after: string;
        related_order: string;
        previous: string;
        next: string;
        pagination: string;
        page_status: string;
    };
    profile: {
        title: string;
        description: string;
        personal_title: string;
        contact_title: string;
        first_name: string;
        last_name: string;
        email: string;
        phone: string;
        preferred_locale: string;
        display_currency: string;
        save: string;
        saved: string;
        edit_email: string;
        edit_phone: string;
        cancel_edit: string;
        new_email: string;
        request_email: string;
        new_phone: string;
        send_phone_code: string;
        phone_code: string;
        confirm_phone: string;
        sensitive_hint: string;
        pending_email: string;
        pending_phone: string;
        email_link_invalid: string;
        phone_code_invalid: string;
    };
    security: {
        title: string;
        description: string;
        current_password: string;
        new_password: string;
        confirm_password: string;
        change_password: string;
        set_password: string;
        password_changed: string;
        social_login_notice: string;
        change_title: string;
        setup_title: string;
        change_description: string;
        setup_description: string;
        recovery_title: string;
        recovery_email: string;
        recovery_whatsapp: string;
        recovery_action: string;
    };
    support: {
        title: string;
        description: string;
        whatsapp_title: string;
        whatsapp_description: string;
        whatsapp_action: string;
        email_title: string;
        email_description: string;
        email_action: string;
        order_context: string;
        unavailable_title: string;
        unavailable_description: string;
    };
    verification: {
        verified: string;
        unverified: string;
        pending: string;
        send_code: string;
        verify: string;
        code: string;
    };
    statuses: Record<AccountOrderStatus, string>;
    actions: {
        view_order: string;
        view_all: string;
        pay_now: string;
        retry_payment: string;
        provide_details: string;
        retry: string;
        back_to_account: string;
    };
    accessibility: {
        current_page: string;
        open_navigation: string;
        close_navigation: string;
        order_status: string;
    };
    errors: {
        section_title: string;
        section_description: string;
        save_failed: string;
        unexpected: string;
    };
};

export type AccountPageShellProps = {
    accountIdentity: { name: string; greeting: string };
    accountNavigation: AccountNavigationItem[];
    accountUi: AccountTranslations;
    adminUrl?: string | null;
    cartCount: number;
    direction: 'rtl' | 'ltr';
    displayCurrency: string;
    displayCurrencies: string[];
    locale: StoreLocale;
    logoutUrl: string;
    storeShell: StoreShellConfig;
    ui: StoreShellTranslations;
};

export type AccountOverviewPageProps = AccountPageShellProps & {
    activeOrder: AccountOrder | null;
    loyalty: AccountLoyalty | null;
    recentOrders: AccountOrder[];
    summary: {
        orderCount: number;
        openOrderCount: number;
        completedOrderCount: number;
        walletBalance: AccountMoney | null;
    };
};

export type AccountOrdersPageProps = AccountPageShellProps & {
    filters: { status: 'all' | 'open' | 'completed' };
    orders: AccountOrder[];
    pagination: {
        currentPage: number;
        lastPage: number;
        perPage: number;
        total: number;
        nextUrl: string | null;
        previousUrl: string | null;
    };
};

export type AccountLiveOrderPageProps = AccountPageShellProps & {
    order: {
        id: string;
        number: string;
        status: AccountOrderStatus;
        placedAt: string;
        total: AccountMoney;
        refreshable: boolean;
        paymentStartUrl: string | null;
        items: Array<{
            id: string;
            name: string;
            status: AccountOrderStatus;
            quantity: number;
            total: AccountMoney;
            credentialsPresent: boolean;
            manualFulfillment: {
                credentialsUrl: string | null;
                squadImageUrl: string | null;
                platform: 'playstation' | 'pc';
                pcLauncher?: 'ea_app' | 'steam';
                targetRank?: number;
                urgent?: boolean;
                matchesPlayed?: number;
                fromDivision?:
                    '7' | '6' | '5' | '4' | '3' | '2' | '1' | 'elite';
                toDivision?: '7' | '6' | '5' | '4' | '3' | '2' | '1' | 'elite';
            } | null;
        }>;
    };
};

export type AccountWalletEntryType =
    'credit' | 'debit' | 'refund' | 'adjustment';

export type AccountWalletEntry = {
    id: string;
    sequence: number;
    type: AccountWalletEntryType;
    effect: 'credit' | 'debit' | 'neutral';
    amount: AccountMoney;
    balanceAfter: AccountMoney;
    createdAt: string | null;
    order: { number: string; url: string } | null;
};

export type WalletStatus = 'coming_soon' | 'active' | 'unavailable';

export type AccountWalletPageProps = AccountPageShellProps & {
    wallet: {
        exists: boolean;
        status?: WalletStatus;
        balance: AccountMoney | null;
        entries: AccountWalletEntry[];
        pagination: {
            currentPage: number;
            lastPage: number;
            perPage: number;
            total: number;
            nextUrl: string | null;
            previousUrl: string | null;
        };
    };
    loyalty: AccountLoyalty | null;
};

export type AccountProfilePageProps = AccountPageShellProps & {
    profile: {
        firstName: string;
        lastName: string;
        email: { value: string; verified: boolean; pending: string | null };
        phone: {
            value: string | null;
            verified: boolean;
            pending: string | null;
        };
        preferredLocale: 'ar' | 'en';
        displayCurrency: string;
    };
    security: {
        passwordMode: 'change' | 'setup';
        passwordRules: string;
    };
    securityActions: {
        changePasswordUrl: string;
        setupPasswordUrl: string;
    };
    profileActions: {
        updateUrl: string;
        emailRequestUrl: string;
        phoneRequestUrl: string;
        phoneConfirmUrl: string;
    };
};

export type AccountSecurityPageProps = AccountPageShellProps & {
    security: {
        passwordMode: 'change' | 'setup';
        passwordRules: string;
        recoveryMode: 'email' | 'whatsapp';
        recoveryUrl: string;
    };
    securityActions: {
        changePasswordUrl: string;
        setupPasswordUrl: string;
    };
};

export type AccountSupportPageProps = AccountPageShellProps & {
    support: {
        available: boolean;
        emailUrl: string | null;
        orderNumber: string | null;
        whatsappUrl: string | null;
    };
};
