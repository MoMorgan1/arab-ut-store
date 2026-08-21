export type AdminMfaRoutes = {
    enable: string;
    confirm: string;
    qrCode: string;
    recoveryCodes: string;
    regenerateRecoveryCodes: string;
    disable: string;
};

export type AdminMfaState = {
    passwordConfigured: boolean;
    enabled: boolean;
    confirmed: boolean;
    routes: AdminMfaRoutes;
};

export type AdminTranslations = {
    brand: string;
    common: {
        logout: string;
        noData: string;
        retry: string;
        cancel: string;
    };
    navigation: {
        overview: string;
        security: string;
        open: string;
        close: string;
    };
    overview: {
        headTitle: string;
        title: string;
        description: string;
        range7: string;
        range30: string;
        receivedOrders: string;
        inProgressOrders: string;
        waitingForCustomer: string;
        pendingPayments: string;
        failedPayments: string;
        failedRefunds: string;
        capturedRevenue: string;
        oldestUnresolved: string;
        recentAudit: string;
        noUnresolved: string;
        noAudit: string;
    };
    statuses: Record<string, string>;
    mfa: {
        headTitle: string;
        eyebrow: string;
        title: string;
        description: string;
        startTitle: string;
        startDescription: string;
        enable: string;
        enabling: string;
        scanTitle: string;
        scanDescription: string;
        sessionExpired: string;
        signIn: string;
        accessDenied: string;
        returnToStore: string;
        passwordConfirmationExpired: string;
        confirmPasswordAgain: string;
        rateLimited: string;
        retryAfterWait: string;
        qrAlt: string;
        confirmCode: string;
        confirm: string;
        confirming: string;
        configured: string;
        configuredDescription: string;
        showRecoveryCodes: string;
        hideRecoveryCodes: string;
        recoveryTitle: string;
        recoveryWarning: string;
        regenerateRecoveryCodes: string;
        regenerateTitle: string;
        regenerateDescription: string;
        confirmRegenerate: string;
        regenerating: string;
        setupPassword: string;
        setupPasswordDescription: string;
        openAccountSecurity: string;
        failed: string;
        invalidCode: string;
    };
};

export type AdminNavigationItem = {
    key: 'overview' | 'security';
    label: string;
    url: string;
};

export type AdminIdentity = {
    name: string;
    role: 'admin' | 'staff';
};

export type AdminMoney = {
    amountMinor: string;
    currency: 'SAR';
};

export type AdminOverviewPageProps = {
    locale: 'ar' | 'en';
    direction: 'rtl' | 'ltr';
    adminUi: AdminTranslations;
    adminIdentity: AdminIdentity;
    adminNavigation: AdminNavigationItem[];
    permissions: string[];
    overview: {
        rangeDays: 7 | 30;
        orders: {
            received: number;
            inProgress: number;
            waitingForCustomer: number;
        };
        payments: { pending: number; failed: number };
        refunds: { failed: number };
        capturedRevenue: AdminMoney;
        oldestUnresolvedOrder: null | {
            id: string;
            number: string;
            status: string;
            placedAt: string;
        };
        recentAuditEvents: null | Array<{
            id: string;
            action: string;
            createdAt: string;
        }>;
    };
    rangeOptions: Array<{
        days: 7 | 30;
        label: string;
        url: string;
        active: boolean;
    }>;
    logoutUrl: string;
};

export type AdminMfaPageProps = {
    locale: 'ar' | 'en';
    direction: 'rtl' | 'ltr';
    adminUi: Pick<AdminTranslations, 'brand' | 'mfa'> & {
        common: Pick<AdminTranslations['common'], 'cancel' | 'retry'>;
    };
    mfa: AdminMfaState;
};
