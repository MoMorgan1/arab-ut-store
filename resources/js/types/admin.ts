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
        orders: string;
        customers: string;
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
        totalOrders: string;
        newCustomers: string;
        needsAttention: string;
        previousPeriod: string;
        newThisPeriod: string;
        noChange: string;
        revenueTrendTitle: string;
        revenueTrendDescription: string;
        orderDistributionTitle: string;
        orderDistributionDescription: string;
        revenueTableAria: string;
        date: string;
        revenue: string;
        status: string;
        count: string;
        noRevenue: string;
        noOrdersInPeriod: string;
        recentOrdersTitle: string;
        recentOrdersDescription: string;
        orderNumber: string;
        orderTotal: string;
        orderPlacedAt: string;
        noRecentOrders: string;
        attentionRailTitle: string;
    };
    orders: {
        headTitle: string;
        title: string;
        description: string;
        searchPlaceholder: string;
        searchLabel: string;
        searchButton: string;
        allStatuses: string;
        allServices: string;
        allPlatforms: string;
        allPaymentStatuses: string;
        filterStatus: string;
        filterService: string;
        filterPlatform: string;
        filterPayment: string;
        dateFrom: string;
        dateTo: string;
        resetFilters: string;
        clearSearch: string;
        columns: string;
        toggleColumns: string;
        order: string;
        customer: string;
        status: string;
        service: string;
        platform: string;
        items: string;
        payment: string;
        total: string;
        placedAt: string;
        noOrders: string;
        noOrdersMatching: string;
        noPayment: string;
        perPage: string;
        page: string;
        of: string;
        showing: string;
        to: string;
        results: string;
        previous: string;
        next: string;
        selectedRows: string;
        selectAll: string;
        selectRow: string;
        loading: string;
        errorTitle: string;
        loadFailed: string;
        tableLabel: string;
        firstPage: string;
        lastPage: string;
        sortAscending: string;
        sortDescending: string;
        sortBy: string;
        services: Record<string, string>;
        platforms: Record<string, string>;
    };
    orderDetail: {
        headTitle: string;
        title: string;
        backToOrders: string;
        customerSection: string;
        paymentSection: string;
        itemsSection: string;
        historySection: string;
        auditSection: string;
        transitionsTitle: string;
        transitionsDescription: string;
        noHistory: string;
        noAudit: string;
        noItems: string;
        noDiscounts: string;
        noPayments: string;
        noRefunds: string;
        customerName: string;
        customerEmail: string;
        customerPhone: string;
        subtotal: string;
        discount: string;
        wallet: string;
        payment: string;
        total: string;
        currency: string;
        placedAt: string;
        paidAt: string;
        completedAt: string;
        cancelledAt: string;
        item: string;
        service: string;
        platform: string;
        quantity: string;
        unitPrice: string;
        status: string;
        source: string;
        actor: string;
        date: string;
        changeStatusTo: string;
        confirmModalTitle: string;
        confirmModalDescription: string;
        confirmCancelDescription: string;
        confirmCompleteDescription: string;
        confirmButton: string;
        cancelButton: string;
        updating: string;
        statusUpdated: string;
        conflictError: string;
        transitionFailed: string;
        forbiddenTransition: string;
        noTransitionsAvailable: string;
        secrets: {
            title: string;
            loading: string;
            retryButton: string;
            copyButton: string;
            copied: string;
            purgedNotice: string;
            revealedCredentialsTitle: string;
            genericError: string;
            networkError: string;
            forbiddenError: string;
        };
        refundsTitle: string;
        refund: {
            title: string;
            description: string;
            amountLabel: string;
            reasonLabel: string;
            reasonPlaceholder: string;
            reasonRequired: string;
            reasonMaxLength: string;
            submitButton: string;
            processingButton: string;
            confirmModalTitle: string;
            confirmModalDescription: string;
            confirmButton: string;
            cancelButton: string;
            successTitle: string;
            successMessage: string;
            errorTitle: string;
            fullRefundRequired: string;
            unavailable: string;
            providerUnavailable: string;
            rateLimited: string;
            rateLimitedGeneric: string;
            genericError: string;
            networkError: string;
            passwordModalTitle: string;
            passwordModalDescription: string;
            passwordLabel: string;
            passwordPlaceholder: string;
            confirmPasswordButton: string;
            confirmingPassword: string;
            invalidPassword: string;
        };
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
    customers: {
        actions: string;
        headTitle: string;
        title: string;
        description: string;
        searchPlaceholder: string;
        searchLabel: string;
        searchButton: string;
        allStatuses: string;
        filterStatus: string;
        statusActive: string;
        statusSuspended: string;
        dateFrom: string;
        dateTo: string;
        resetFilters: string;
        clearSearch: string;
        columns: string;
        toggleColumns: string;
        customer: string;
        name: string;
        email: string;
        phone: string;
        status: string;
        orders: string;
        ordersCount: string;
        totalSpent: string;
        walletBalance: string;
        lastOrderAt: string;
        createdAt: string;
        noCustomers: string;
        noCustomersMatching: string;
        noPhone: string;
        noOrders: string;
        perPage: string;
        page: string;
        of: string;
        showing: string;
        to: string;
        results: string;
        previous: string;
        next: string;
        selectedRows: string;
        selectAll: string;
        selectRow: string;
        loading: string;
        errorTitle: string;
        loadFailed: string;
        tableLabel: string;
        firstPage: string;
        lastPage: string;
        sortAscending: string;
        sortDescending: string;
        sortBy: string;
        viewDetail: string;
    };
    customerDetail: {
        networkError: string;
        suspendedMessage: string;
        reactivatedMessage: string;
        headTitle: string;
        title: string;
        backToCustomers: string;
        identitySection: string;
        ordersSection: string;
        recentOrders: string;
        walletSection: string;
        recentWalletEntries: string;
        auditSection: string;
        accountStatus: string;
        statusDescription: string;
        suspendButton: string;
        reactivateButton: string;
        suspendTitle: string;
        reactivateTitle: string;
        suspendConsequence: string;
        reactivateConsequence: string;
        reasonLabel: string;
        reasonRequired: string;
        caseReferenceLabel: string;
        caseReferencePlaceholder: string;
        caseReferenceHelp: string;
        confirmSuspend: string;
        confirmReactivate: string;
        cancelButton: string;
        suspending: string;
        reactivating: string;
        statusUpdated: string;
        conflictError: string;
        updateFailed: string;
        forbiddenError: string;
        name: string;
        email: string;
        phone: string;
        preferredLocale: string;
        registeredAt: string;
        emailVerified: string;
        emailUnverified: string;
        phoneVerified: string;
        phoneUnverified: string;
        ordersCount: string;
        totalSpent: string;
        lastOrderAt: string;
        noOrders: string;
        walletBalance: string;
        walletEntriesCount: string;
        noWalletEntries: string;
        noAudit: string;
        orderNumber: string;
        orderStatus: string;
        orderTotal: string;
        orderPlacedAt: string;
        entryType: string;
        entryAmount: string;
        entryReference: string;
        entryDate: string;
        actor: string;
        action: string;
        date: string;
        reasons: Record<
            | 'fraud_suspected'
            | 'chargeback'
            | 'abuse'
            | 'customer_request'
            | 'account_recovery'
            | 'other_reviewed',
            string
        >;
        passwordModalTitle: string;
        passwordModalDescription: string;
        passwordLabel: string;
        passwordPlaceholder: string;
        confirmPasswordButton: string;
        confirmingPassword: string;
        invalidPassword: string;
    };
};

export type AdminNavigationItem = {
    key: 'overview' | 'orders' | 'customers' | 'security';
    label: string;
    url: string;
};

export type AdminIdentity = {
    name: string;
    role: 'admin' | 'staff';
};

export type AdminMoney<Currency extends string = 'SAR'> = {
    amountMinor: string;
    currency: Currency;
};

export type AdminRevenueTrendPoint = {
    date: string;
    amountMinor: string;
    currency: 'SAR';
};

export type AdminOrderStatusCount = {
    status:
        | 'pending_payment'
        | 'received'
        | 'in_progress'
        | 'waiting_for_customer'
        | 'completed'
        | 'cancelled'
        | 'refunded';
    count: number;
};

export type AdminRecentOrder = {
    id: string;
    number: string;
    status: string;
    placedAt: string;
    total: AdminMoney;
};

export type AdminComparisonCount = {
    current: number;
    previous: number;
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
        previousCapturedRevenue: AdminMoney;
        totalOrders: AdminComparisonCount;
        newCustomers: AdminComparisonCount;
        attentionCount: number;
        revenueTrend: AdminRevenueTrendPoint[];
        orderStatusDistribution: AdminOrderStatusCount[];
        recentOrders: AdminRecentOrder[];
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

export type AdminOrderCustomer = {
    id: string;
    name: string;
    email: string;
    phone: string | null;
};

export type AdminOrderRow = {
    id: string;
    orderNumber: string;
    customer: AdminOrderCustomer;
    status: string;
    serviceTypes: string[];
    platforms: string[];
    itemCount: number;
    latestPaymentStatus: string | null;
    total: AdminMoney<string>;
    placedAt: string;
};

export type AdminOrdersQueryState = {
    search?: string | null;
    status?: string | null;
    service?: string | null;
    platform?: string | null;
    payment_status?: string | null;
    date_from?: string | null;
    date_to?: string | null;
    sort: 'placed_at' | 'total' | 'order_number';
    direction: 'asc' | 'desc';
    per_page: 15 | 25 | 50 | 100;
    page: number;
};

export type AdminPagination = {
    currentPage: number;
    lastPage: number;
    perPage: number;
    total: number;
    from: number | null;
    to: number | null;
};

export type AdminFilterOption = {
    value: string;
    label: string;
};

export type AdminOrdersPageProps = {
    locale: 'ar' | 'en';
    direction: 'rtl' | 'ltr';
    adminUi: AdminTranslations;
    adminIdentity: AdminIdentity;
    adminNavigation: AdminNavigationItem[];
    permissions: string[];
    orders: AdminOrderRow[];
    pagination: AdminPagination;
    filters: AdminOrdersQueryState;
    filterOptions: {
        statuses: AdminFilterOption[];
        services: AdminFilterOption[];
        platforms: AdminFilterOption[];
        paymentStatuses: AdminFilterOption[];
        perPageOptions: number[];
    };
    logoutUrl: string;
};

export type AdminStatusHistoryEntry = {
    id: string;
    status: string;
    source: string | null;
    previousStatus: string | null;
    newStatus: string | null;
    createdAt: string;
    actor: { name: string; role: string } | null;
};

export type AdminOrderDetailItem = {
    id: string;
    name: string;
    serviceType: string;
    platform: string;
    quantity: number;
    unitPrice: AdminMoney<string>;
    subtotal: AdminMoney<string>;
    discount: AdminMoney<string>;
    total: AdminMoney<string>;
    status: string;
    configuration: Record<string, unknown> | null;
    hasSecret: boolean;
    maskedSummary: Record<string, unknown> | null;
    statusHistory: AdminStatusHistoryEntry[];
};

export type AdminOrderPayment = {
    id: string;
    status: string;
    currency: string;
    amount: AdminMoney<string>;
    capturedAmount: AdminMoney<string>;
    refundedAmount: AdminMoney<string>;
    paidAt: string | null;
    createdAt: string;
};

export type AdminOrderRefund = {
    id: string;
    status: string;
    method: string;
    amount: AdminMoney<string>;
    reason: string | null;
    completedAt: string | null;
    createdAt: string;
};

export type AdminOrderDiscount = {
    id: string;
    type: string;
    label: string;
    amount: AdminMoney<string>;
};

export type AdminAuditLogEntry = {
    id: string;
    action: string;
    actor: { name: string; role: string } | null;
    createdAt: string;
};

export type AdminOrderDetail = {
    id: string;
    orderNumber: string;
    status: string;
    currency: string;
    placedAt: string | null;
    paidAt: string | null;
    completedAt: string | null;
    cancelledAt: string | null;
    customer: AdminOrderCustomer;
    money: {
        subtotal: AdminMoney<string>;
        discount: AdminMoney<string>;
        wallet: AdminMoney<string>;
        payment: AdminMoney<string>;
        total: AdminMoney<string>;
    };
    items: AdminOrderDetailItem[];
    payments: AdminOrderPayment[];
    refunds: AdminOrderRefund[];
    discounts: AdminOrderDiscount[];
    statusHistory: AdminStatusHistoryEntry[];
    auditContext: AdminAuditLogEntry[] | null;
};

export type AdminOrderDetailPageProps = {
    locale: 'ar' | 'en';
    direction: 'rtl' | 'ltr';
    adminUi: AdminTranslations;
    adminIdentity: AdminIdentity;
    adminNavigation: AdminNavigationItem[];
    permissions: string[];
    order: AdminOrderDetail;
    allowedTransitions: string[];
    transitionUrl: string;
    revealUrlTemplate?: string;
    refund: {
        eligible: boolean;
        amountMinor: string;
        currency: string;
    };
    refundUrl: string;
    confirmPasswordUrl?: string;
    logoutUrl: string;
};

export type AdminCustomerRow = {
    id: string;
    name: string;
    email: string;
    phone: string | null;
    isActive: boolean;
    createdAt: string;
    ordersCount: number;
    lastOrderAt: string | null;
    totalSpent: AdminMoney<'SAR'>;
    walletBalance: AdminMoney<'SAR'>;
};

export type AdminCustomersQueryState = {
    search?: string | null;
    status?: 'active' | 'suspended' | null;
    date_from?: string | null;
    date_to?: string | null;
    sort:
        | 'created_at'
        | 'name'
        | 'orders_count'
        | 'last_order_at'
        | 'total_spent';
    direction: 'asc' | 'desc';
    per_page: 15 | 25 | 50 | 100;
    page: number;
};

export type AdminCustomersPageProps = {
    locale: 'ar' | 'en';
    direction: 'rtl' | 'ltr';
    adminUi: AdminTranslations;
    adminIdentity: AdminIdentity;
    adminNavigation: AdminNavigationItem[];
    permissions: string[];
    customers: AdminCustomerRow[];
    pagination: AdminPagination;
    filters: AdminCustomersQueryState;
    filterOptions: {
        statuses: AdminFilterOption[];
        perPageOptions: number[];
    };
    logoutUrl: string;
};

export type AdminCustomerRecentOrder = {
    id: string;
    orderNumber: string;
    status: string;
    total: AdminMoney<string>;
    placedAt: string | null;
};

export type AdminCustomerWalletEntry = {
    id: string;
    type: string;
    direction: 'credit' | 'debit' | 'neutral';
    amount: AdminMoney<string>;
    createdAt: string;
    reference: string | null;
};

export type AdminCustomerDetail = {
    id: string;
    name: string;
    firstName: string;
    lastName: string;
    email: string;
    phone: string | null;
    preferredLocale: string;
    isActive: boolean;
    createdAt: string;
    emailVerifiedAt: string | null;
    phoneVerifiedAt: string | null;
    ordersSummary: {
        ordersCount: number;
        totalSpent: AdminMoney<'SAR'>;
        lastOrderAt: string | null;
    };
    recentOrders: AdminCustomerRecentOrder[];
    walletSummary: {
        balance: AdminMoney<'SAR'>;
        entriesCount: number;
    };
    recentWalletEntries: AdminCustomerWalletEntry[];
    recentAuditLogs: Array<{
        id: string;
        action: string;
        actor: { name: string; role: string } | null;
        createdAt: string;
        metadata?: Record<string, unknown>;
    }> | null;
};

export type AdminCustomerDetailPageProps = {
    locale: 'ar' | 'en';
    direction: 'rtl' | 'ltr';
    adminUi: AdminTranslations;
    adminIdentity: AdminIdentity;
    adminNavigation: AdminNavigationItem[];
    permissions: string[];
    customer: AdminCustomerDetail;
    statusUrl: string;
    confirmPasswordUrl?: string;
    logoutUrl: string;
};
