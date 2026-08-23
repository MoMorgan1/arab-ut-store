export type AdminMfaRoutes = {
    enable: string;
    confirm: string;
    qrCode: string;
    recoveryCodes: string;
    regenerateRecoveryCodes: string;
    disable: string;
    forgetTrustedDevices: string;
};

export type AdminMfaState = {
    passwordConfigured: boolean;
    enabled: boolean;
    confirmed: boolean;
    /** Browsers currently allowed to skip the TOTP challenge. */
    trustedDeviceCount: number;
    /** How long a browser stays trusted after passing the challenge. */
    trustedDeviceDays: number;
    routes: AdminMfaRoutes;
};

export type AdminTranslations = {
    brand: string;
    common: {
        logout: string;
        noData: string;
        retry: string;
        cancel: string;
        dismiss: string;
    };
    navigation: {
        overview: string;
        orders: string;
        customers: string;
        products: string;
        marketingLoyalty?: string;
        settings: string;
        marketing: string;
        open: string;
        close: string;
        quick?: string;
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
        filters?: string;
        apply?: string;
        clearAll?: string;
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
        trustedDevicesTitle: string;
        trustedDevicesDescription: string;
        trustedDevicesNone: string;
        forgetTrustedDevices: string;
        forgettingTrustedDevices: string;
        forgetTrustedDevicesTitle: string;
        forgetTrustedDevicesDescription: string;
        confirmForgetTrustedDevices: string;
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
        conflictTitle: string;
        contactUpdated: string;
        conflictError: string;
        updateFailed: string;
        forbiddenError: string;
        name: string;
        email: string;
        phone: string;
        editDetailsButton: string;
        editContactTitle: string;
        editContactDescription: string;
        firstNameLabel: string;
        lastNameLabel: string;
        emailLabel: string;
        phoneLabel: string;
        phoneHelp: string;
        saveButton: string;
        savingButton: string;
        contactUpdatedMessage: string;
        contactConflictError: string;
        updateContactFailed: string;
        forbiddenContactError: string;
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
        currentTier: string;
        lifetimeEligibleSpend: string;
        noTier: string;
        adjustBalance: string;
        adjustBalanceTitle: string;
        adjustBalanceDescription: string;
        adjustTypeLabel: string;
        credit: string;
        debit: string;
        amountSarLabel: string;
        amountHalalahHelp: string;
        adjustmentReasonLabel: string;
        adjustmentReasonPlaceholder: string;
        submitAdjustment: string;
        adjustingBalance: string;
        walletAdjustSuccess: string;
        walletInsufficientBalance: string;
        walletAdjustFailed: string;
        walletPasswordModalTitle: string;
        walletPasswordModalDescription: string;
    };
    settings: {
        headTitle: string;
        title: string;
        description: string;
        securitySection: string;
        securityDescription: string;
        teamSection: string;
        teamDescription: string;
        addMemberButton: string;
        addMemberTitle: string;
        addMemberDescription: string;
        addMemberEmailLabel: string;
        addMemberRoleLabel: string;
        addMemberSubmit: string;
        addMemberSubmitting: string;
        servicePricingSection: string;
        servicePricingDescription: string;
        servicePricing: {
            futChampions: string;
            rivals: string;
            urgentSurcharge: string;
            version: string;
            lastUpdated: string;
            active: string;
            inactive: string;
            editPrices: string;
            editingPrices: string;
            tableRank: string;
            tableStep: string;
            tablePrice: string;
            tableHalalah: string;
            ranks: Record<string, string>;
            steps: Record<string, string>;
            editDialog: {
                title: string;
                description: string;
                confirm: string;
                cancel: string;
                halalahHint: string;
            };
            deactivateDialog: {
                title: string;
                description: string;
                confirm: string;
                cancel: string;
            };
            activateDialog: {
                title: string;
                description: string;
                confirm: string;
                cancel: string;
            };
            messages: {
                pricingUpdated: string;
                statusUpdated: string;
                conflictError: string;
                validationError: string;
            };
            actions: {
                deactivate: string;
                deactivating: string;
                reactivate: string;
                reactivating: string;
            };
        };
        addStaffHint: string;
        selfBadge: string;
        columns: {
            member: string;
            name: string;
            email: string;
            role: string;
            status: string;
            mfa: string;
            joined: string;
            actions: string;
        };
        roles: {
            admin: string;
            staff: string;
        };
        status: {
            active: string;
            inactive: string;
        };
        mfa: {
            confirmed: string;
            pending: string;
        };
        actions: {
            applyRole: string;
            applyingRole: string;
            changeRole: string;
            deactivate: string;
            deactivating: string;
            reactivate: string;
            reactivating: string;
            roleSelectLabel: string;
        };
        roleDialog: {
            title: string;
            description: string;
            confirm: string;
            cancel: string;
        };
        deactivateDialog: {
            title: string;
            description: string;
            confirm: string;
            cancel: string;
        };
        reactivateDialog: {
            title: string;
            description: string;
            confirm: string;
            cancel: string;
        };
        messages: {
            roleUpdated: string;
            statusUpdated: string;
            conflictError: string;
            genericError: string;
            networkError: string;
            forbiddenError: string;
            lastAdminError: string;
            grantSucceeded: string;
            grantNoSuchAccount: string;
            grantSelf: string;
            grantAlreadyGranted: string;
            grantInactiveAccount: string;
        };
    };
    coupons: {
        headTitle: string;
        title: string;
        description: string;
        createButton: string;
        editButton: string;
        createTitle: string;
        editTitle: string;
        codeLabel: string;
        codePlaceholder: string;
        codeHelp: string;
        descriptionArLabel: string;
        descriptionEnLabel: string;
        typeLabel: string;
        typePercent: string;
        typeFixed: string;
        valueLabel: string;
        valuePercentHelp: string;
        valueFixedHelp: string;
        minimumOrderLabel: string;
        minimumOrderHelp: string;
        maximumDiscountLabel: string;
        maximumDiscountHelp: string;
        usageLimitLabel: string;
        usageLimitHelp: string;
        perUserLimitLabel: string;
        perUserLimitHelp: string;
        startsAtLabel: string;
        endsAtLabel: string;
        isActiveLabel: string;
        saveButton: string;
        savingButton: string;
        cancelButton: string;
        columns: {
            code: string;
            type: string;
            window: string;
            usage: string;
            status: string;
            actions: string;
        };
        typePercentBadge: string;
        typeFixedBadge: string;
        unlimited: string;
        usageOf: string;
        always: string;
        from: string;
        until: string;
        window: string;
        active: string;
        inactive: string;
        noCoupons: string;
        toggleTitle: string;
        activateTitle: string;
        deactivateTitle: string;
        activateDescription: string;
        deactivateDescription: string;
        confirmToggle: string;
        messages: {
            created: string;
            updated: string;
            toggled: string;
            genericError: string;
            networkError: string;
            forbiddenError: string;
            conflictError: string;
            validationError: string;
        };
        passwordModalTitle: string;
        passwordModalDescription: string;
        passwordLabel: string;
        passwordPlaceholder: string;
        confirmPasswordButton: string;
        confirmingPassword: string;
        invalidPassword: string;
    };
    products: {
        actions: string;
        allArchived: string;
        allAuthorities: string;
        allServices: string;
        allSources: string;
        allVisibilities: string;
        archivedActive: string;
        archivedArchived: string;
        authority: string;
        authorityAutomation: string;
        authorityManual: string;
        clearSearch: string;
        columns: string;
        createdAt: string;
        description: string;
        errorTitle: string;
        filterArchived: string;
        filterAuthority: string;
        filterService: string;
        filterSource: string;
        filterVisibility: string;
        filters: string;
        firstPage: string;
        headTitle: string;
        lastPage: string;
        loadFailed: string;
        loading: string;
        name: string;
        next: string;
        noProducts: string;
        noProductsMatching: string;
        of: string;
        page: string;
        perPage: string;
        previous: string;
        product: string;
        resetFilters: string;
        results: string;
        searchButton: string;
        searchLabel: string;
        searchPlaceholder: string;
        selectAll: string;
        selectRow: string;
        selectedRows: string;
        service: string;
        showing: string;
        slug: string;
        sortAscending: string;
        sortBy: string;
        sortDescending: string;
        sortOrder: string;
        source: string;
        sourceManual: string;
        tableLabel: string;
        title: string;
        to: string;
        toggleColumns: string;
        updatedAt: string;
        variants: string;
        variantsCount: string;
        viewDetail: string;
        visibility: string;
        visibilityHidden: string;
        visibilityVisible: string;
    };
    productDetail: {
        action: string;
        active: string;
        activeFlag: string;
        actor: string;
        altAr: string;
        altEn: string;
        archived: string;
        auditSection: string;
        authority: string;
        authorityManual: string;
        authorityAutomation: string;
        automationSection: string;
        backToProducts: string;
        cancelButton: string;
        category: string;
        completedAt: string;
        confirmPasswordButton: string;
        confirmingPassword: string;
        conflictError: string;
        conflictTitle: string;
        date: string;
        descriptionArLabel: string;
        descriptionEnLabel: string;
        descriptionsSection: string;
        disk: string;
        editButton: string;
        editDescription: string;
        editTitle: string;
        forbiddenError: string;
        headTitle: string;
        hidden: string;
        inactiveFlag: string;
        invalidPassword: string;
        isVisibleHelp: string;
        isVisibleLabel: string;
        lastSnapshot: string;
        lastUpdated: string;
        manualSource: string;
        market: string;
        mediaSection: string;
        nameArLabel: string;
        nameEnLabel: string;
        networkError: string;
        noAudit: string;
        noAutomationHistory: string;
        noCategory: string;
        noDescriptionAr: string;
        noDescriptionEn: string;
        noMedia: string;
        noVariants: string;
        notEditableError: string;
        outcome: string;
        passwordLabel: string;
        passwordModalDescription: string;
        passwordModalTitle: string;
        passwordPlaceholder: string;
        path: string;
        platform: string;
        price: string;
        priceVersion: string;
        pricesReadOnlyNotice: string;
        productInformation: string;
        productUpdated: string;
        productUpdatedMessage: string;
        quantity: string;
        readOnlyBadge: string;
        readOnlyNotice: string;
        registeredAt: string;
        runId: string;
        runStatus: string;
        salePrice: string;
        saveButton: string;
        savingButton: string;
        serviceType: string;
        sku: string;
        slug: string;
        sortOrder: string;
        sortOrderHelp: string;
        sortOrderLabel: string;
        source: string;
        startedAt: string;
        status: string;
        syncedAt: string;
        title: string;
        updateFailed: string;
        variantsSection: string;
        variantsCount: string;
        visibility: string;
        visible: string;
    };
    loyalty: {
        headTitle: string;
        title: string;
        description: string;
        kpi: {
            cashbackLast30Days: string;
            totalCustomers: string;
            customersPerTier: string;
        };
        table: {
            rank: string;
            tier: string;
            nameAr: string;
            nameEn: string;
            threshold: string;
            cashbackRate: string;
            status: string;
            actions: string;
            edit: string;
            active: string;
            inactive: string;
            noTiers: string;
        };
        editDialog: {
            title: string;
            description: string;
            nameArLabel: string;
            nameEnLabel: string;
            thresholdLabel: string;
            cashbackLabel: string;
            cashbackBpHelp: string;
            activeLabel: string;
            activeHelp: string;
            saveButton: string;
            savingButton: string;
            cancelButton: string;
            successMessage: string;
            updateFailed: string;
            passwordModalTitle: string;
            passwordModalDescription: string;
            passwordLabel: string;
            passwordPlaceholder: string;
            confirmPasswordButton: string;
            confirmingPassword: string;
            invalidPassword: string;
        };
        validation: {
            rankOneZero: string;
            strictlyIncreasing: string;
        };
    };
};

export type AdminNavigationItem = {
    key:
        | 'overview'
        | 'orders'
        | 'customers'
        | 'marketing'
        | 'products'
        | 'products'
        | 'marketingLoyalty'
        | 'settings';
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

export type AdminTeamMember = {
    id: string;
    name: string;
    email: string;
    role: 'admin' | 'staff';
    isActive: boolean;
    mfaConfirmed: boolean;
    createdAt: string;
};

export type AdminTeamData = {
    members: AdminTeamMember[];
    currentUserId: string;
};

export type AdminTeamUrls = {
    grantUrl: string;
    roleUrlTemplate: string;
    statusUrlTemplate: string;
};

export type AdminServicePricingSchedule = {
    serviceType: 'fut_champions' | 'rivals';
    version: number;
    isActive: boolean;
    updatedAt: string;
    configuration: Record<string, unknown>;
};

export type AdminServicePricingData = {
    schedules: AdminServicePricingSchedule[];
};

export type AdminServicePricingUrls = {
    updateUrlTemplate: string;
    statusUrlTemplate: string;
};

export type AdminSettingsPageProps = {
    locale: 'ar' | 'en';
    direction: 'rtl' | 'ltr';
    adminUi: AdminTranslations;
    adminIdentity: AdminIdentity;
    adminNavigation: AdminNavigationItem[];
    permissions: string[];
    mfa: AdminMfaState;
    team: AdminTeamData | null;
    teamUrls: AdminTeamUrls | null;
    servicePricing: AdminServicePricingData | null;
    servicePricingUrls: AdminServicePricingUrls | null;
    confirmPasswordUrl?: string;
    logoutUrl: string;
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
    number: string | null;
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
    number: string | null;
    name: string;
    firstName: string;
    lastName: string;
    email: string;
    phone: string | null;
    preferredLocale: string;
    isActive: boolean;
    createdAt: string;
    updatedAt: string;
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
    loyalty?: {
        eligibleSpend: AdminMoney<'SAR'>;
        currentTier: {
            key: string;
            name: string;
            minimum: AdminMoney<'SAR'>;
        } | null;
        nextTier: {
            key: string;
            name: string;
            minimum: AdminMoney<'SAR'>;
        } | null;
        remaining: AdminMoney<'SAR'> | null;
        progressPercent: number;
    } | null;
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
    contactUrl: string;
    walletAdjustUrl: string;
    confirmPasswordUrl?: string;
    logoutUrl: string;
};

export type AdminLoyaltyTier = {
    id: string;
    key: string;
    nameAr: string;
    nameEn: string;
    rank: number;
    minimumLifetimeSpend: AdminMoney<'SAR'>;
    cashbackBasisPoints: number;
    cashbackPercent: string;
    isActive: boolean;
    updatedAt: string;
};

export type AdminLoyaltyKpis = {
    customersPerTier: Record<string, number>;
    cashbackCreditedLast30Days: AdminMoney<'SAR'>;
};

export type AdminLoyaltyPageProps = {
    locale: 'ar' | 'en';
    direction: 'rtl' | 'ltr';
    adminUi: AdminTranslations;
    adminIdentity: AdminIdentity;
    adminNavigation: AdminNavigationItem[];
    permissions: string[];
    tiers: AdminLoyaltyTier[];
    kpis: AdminLoyaltyKpis;
    updateTierUrlTemplate: string;
    confirmPasswordUrl?: string;
    logoutUrl: string;
};

export type AdminCouponRow = {
    id: string;
    code: string;
    discountType: 'percent' | 'fixed';
    value: number;
    minimumOrderHalalah: number;
    maximumDiscountHalalah: number | null;
    usageLimit: number | null;
    perUserLimit: number | null;
    usedCount: number;
    startsAt: string | null;
    endsAt: string | null;
    isActive: boolean;
    createdAt: string;
};

export type AdminCouponsQueryState = {
    search?: string | null;
    sort: 'created_at' | 'code' | 'used_count';
    direction: 'asc' | 'desc';
    per_page: 15 | 25 | 50;
    page: number;
};

export type AdminCouponsPageProps = {
    locale: 'ar' | 'en';
    direction: 'rtl' | 'ltr';
    adminUi: AdminTranslations;
    adminIdentity: AdminIdentity;
    adminNavigation: AdminNavigationItem[];
    permissions: string[];
    coupons: AdminCouponRow[];
    pagination: AdminPagination;
    counts: {
        total: number;
        active: number;
    };
    filters: AdminCouponsQueryState;
    logoutUrl: string;
};

export type AdminProductRow = {
    id: string;
    slug: string;
    name: string;
    nameAr: string;
    nameEn: string;
    serviceType: string;
    authority: 'manual' | 'automation';
    source: { name: string; key: string } | null;
    isVisible: boolean;
    sortOrder: number;
    isArchived: boolean;
    variantsCount: number;
    createdAt: string;
    updatedAt: string;
};

export type AdminProductsQueryState = {
    search?: string | null;
    service_type?: string | null;
    authority?: 'manual' | 'automation' | null;
    source?: string | null;
    visibility?: 'visible' | 'hidden' | null;
    archived?: 'active' | 'archived' | null;
    sort: 'name' | 'created_at' | 'updated_at' | 'sort_order';
    direction: 'asc' | 'desc';
    per_page: 15 | 25 | 50 | 100;
    page: number;
};

export type AdminProductsPageProps = {
    locale: 'ar' | 'en';
    direction: 'rtl' | 'ltr';
    adminUi: AdminTranslations;
    adminIdentity: AdminIdentity;
    adminNavigation: AdminNavigationItem[];
    permissions: string[];
    products: AdminProductRow[];
    pagination: AdminPagination;
    filters: AdminProductsQueryState;
    filterOptions: {
        services: AdminFilterOption[];
        authorities: AdminFilterOption[];
        sources: AdminFilterOption[];
        visibilities: AdminFilterOption[];
        archived: AdminFilterOption[];
        perPageOptions: number[];
    };
    logoutUrl: string;
};

export type AdminProductVariant = {
    id: string;
    sku: string;
    serviceType: string;
    platform: string;
    market: string;
    authority: string;
    nameAr: string | null;
    nameEn: string | null;
    quantityK: number | null;
    price: AdminMoney<'SAR'>;
    salePrice: AdminMoney<'SAR'> | null;
    priceVersion: number;
    configuration: Record<string, unknown> | null;
    isActive: boolean;
    createdAt: string;
    updatedAt: string;
};

export type AdminProductMedia = {
    id: string;
    disk: string;
    path: string;
    altAr: string | null;
    altEn: string | null;
    sortOrder: number;
};

export type AdminProductAutomation = {
    runId: string;
    status: string;
    outcome: string;
    completedAt: string | null;
    startedAt: string | null;
    error: string | null;
    syncedAt: string;
};

export type AdminProductDetail = {
    id: string;
    slug: string;
    name: string;
    nameAr: string;
    nameEn: string;
    descriptionAr: string | null;
    descriptionEn: string | null;
    serviceType: string;
    authority: 'manual' | 'automation';
    isEditable: boolean;
    isVisible: boolean;
    sortOrder: number;
    isArchived: boolean;
    archivedAt: string | null;
    createdAt: string;
    updatedAt: string;
    category: { id: string; name: string; slug: string } | null;
    source: { id: string; key: string; name: string; authority: string } | null;
    variants: AdminProductVariant[];
    media: AdminProductMedia[];
    automation: AdminProductAutomation | null;
    recentAuditLogs: Array<{
        id: string;
        action: string;
        actor: { name: string; role: string } | null;
        createdAt: string;
        metadata?: Record<string, unknown>;
    }> | null;
};

export type AdminProductDetailPageProps = {
    locale: 'ar' | 'en';
    direction: 'rtl' | 'ltr';
    adminUi: AdminTranslations;
    adminIdentity: AdminIdentity;
    adminNavigation: AdminNavigationItem[];
    permissions: string[];
    product: AdminProductDetail;
    updateUrl: string;
    confirmPasswordUrl?: string;
    logoutUrl: string;
};
