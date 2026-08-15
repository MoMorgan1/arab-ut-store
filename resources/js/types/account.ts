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
    navigation: {
        label: string;
        overview: string;
        orders: string;
        wallet: string;
        profile: string;
        security: string;
        support: string;
        logout: string;
    };
    overview: {
        title: string;
        description: string;
        orders_metric: string;
        open_orders_metric: string;
        completed_orders_metric: string;
        wallet_metric: string;
        active_order: string;
        recent_orders: string;
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
        refresh_status: string;
        refreshing: string;
        back: string;
    };
    wallet: {
        title: string;
        description: string;
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

export type AccountWalletPageProps = AccountPageShellProps & {
    wallet: {
        exists: boolean;
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
