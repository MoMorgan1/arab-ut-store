import type {
    StoreLocale,
    StoreShellConfig,
    StoreShellTranslations,
} from '@/types/store-shell';

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
    };
};

export type AccountOverviewPageProps = {
    accountUi: AccountTranslations;
    cartCount: number;
    direction: 'rtl' | 'ltr';
    displayCurrency: string;
    displayCurrencies: string[];
    locale: StoreLocale;
    storeShell: StoreShellConfig;
    ui: StoreShellTranslations;
};
