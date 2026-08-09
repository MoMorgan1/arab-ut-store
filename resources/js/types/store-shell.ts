export type StoreLocale = 'ar' | 'en';

export type SimpleStorePageKey =
    | 'cart'
    | 'sbc'
    | 'fut_champions'
    | 'privacy'
    | 'returns'
    | 'warranty'
    | 'ea_backup_codes'
    | 'terms';

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
        legal_navigation: string;
        copyright: string;
        ea_disclaimer: string;
    };
    simple_pages: {
        eyebrow: string;
        back_home: string;
    } & Record<SimpleStorePageKey, { title: string; body: string }>;
};

export type SimpleStorePageProps = {
    direction: 'rtl' | 'ltr';
    displayCurrency: string;
    displayCurrencies: string[];
    locale: StoreLocale;
    page: { key: SimpleStorePageKey; title: string; body: string };
    storeShell: StoreShellConfig;
    ui: StoreShellTranslations;
};
