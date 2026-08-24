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
        conversations: string;
        catalog?: string;
        products: string;
        categories?: string;
        marketingLoyalty?: string;
        settings: string;
        marketing: string;
        marketingCoupons: string;
        marketingPromotions: string;
        more?: string;
        open: string;
        close: string;
        quick?: string;
    };
    more?: {
        headTitle: string;
        title: string;
        description: string;
        groups: {
            catalog: string;
            marketing: string;
            system: string;
        };
        tiles: {
            conversations: { title: string; description: string };
            categories: { title: string; description: string };
            coupons: { title: string; description: string };
            promotions: { title: string; description: string };
            loyalty: { title: string; description: string };
            settings: { title: string; description: string };
        };
        noTiles: string;
    };
    overview: {
        headTitle: string;
        title: string;
        description: string;
        range1: string;
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
        ordersInFlight?: string;
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
        attentionStripTitle?: string;
        viewAllOrders: string;
        viewUnresolvedOrders: string;
        viewAllReports?: string;
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
        activeFilters: string;
        apply: string;
        clearAll: string;
        clearOneFilter: string;
        filters: string;
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
        loading: string;
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
        codeUppercaseHint: string;
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
        minimumOrderEligibleHelp: string;
        maximumDiscountLabel: string;
        maximumDiscountHelp: string;
        usageLimitLabel: string;
        usageLimitHelp: string;
        perUserLimitLabel: string;
        perUserLimitHelp: string;
        cancelledReleasesRedemptionHelp: string;
        startsAtLabel: string;
        endsAtLabel: string;
        isActiveLabel: string;
        isPausedLabel: string;
        isPausedHelp: string;
        firstOrderOnlyLabel: string;
        firstOrderOnlyHelp: string;
        excludesPromotedLabel: string;
        excludesPromotedHelp: string;
        scopeLabel: string;
        scopeHelp: string;
        scopeOrder: string;
        scopeCategory: string;
        scopeProduct: string;
        scopeService: string;
        targetCategoriesLabel: string;
        targetCategoriesPlaceholder: string;
        targetProductsLabel: string;
        targetProductsPlaceholder: string;
        serviceTypeLabel: string;
        serviceTypePlaceholder: string;
        sectionBasics: string;
        sectionDiscount: string;
        sectionAppliesTo: string;
        sectionEligibility: string;
        sectionLimits: string;
        statusAll: string;
        statusActive: string;
        statusScheduled: string;
        statusPaused: string;
        statusExpired: string;
        statusExhausted: string;
        saveButton: string;
        savingButton: string;
        cancelButton: string;
        columns: {
            code: string;
            discount: string;
            type: string;
            scope: string;
            eligibility: string;
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
        paused: string;
        scheduled: string;
        expired: string;
        exhausted: string;
        noCoupons: string;
        noCouponsMatching: string;
        toggleTitle: string;
        activateTitle: string;
        deactivateTitle: string;
        activateDescription: string;
        deactivateDescription: string;
        confirmToggle: string;
        duplicateButton: string;
        duplicateTitle: string;
        duplicateDescription: string;
        duplicateCodeLabel: string;
        duplicateCodePlaceholder: string;
        confirmDuplicate: string;
        duplicating: string;
        pauseButton: string;
        resumeButton: string;
        viewDetails: string;
        backToCoupons: string;
        detailTitle: string;
        performanceTitle: string;
        rulesTitle: string;
        recentRedemptionsTitle: string;
        kpiRedemptions: string;
        kpiUniqueCustomers: string;
        kpiRevenueAttributed: string;
        kpiTotalDiscount: string;
        kpiPaidOrdersNote: string;
        releasedNotice: string;
        chartTitle: string;
        noChartData: string;
        noRecentRedemptions: string;
        orderColumn: string;
        customerColumn: string;
        totalColumn: string;
        discountColumn: string;
        statusColumn: string;
        dateColumn: string;
        viewOrder: string;
        viewCustomer: string;
        filters: string;
        filterScope: string;
        allScopes: string;
        filterDiscountType: string;
        allDiscountTypes: string;
        filterStatus: string;
        allStatuses: string;
        searchPlaceholder: string;
        searchLabel: string;
        clearSearch: string;
        clearAll: string;
        apply: string;
        resetFilters: string;
        activeFilters: string;
        clearOneFilter: string;
        columnsToggle: string;
        unlimitedPerUser: string;
        capAmount: string;
        minAmount: string;
        noCap: string;
        noMin: string;
        allCustomers: string;
        progressText: string;
        progressUnlimited: string;
        messages: {
            created: string;
            updated: string;
            duplicated: string;
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
    promotions: {
        headTitle: string;
        title: string;
        description: string;
        createButton: string;
        editButton: string;
        createTitle: string;
        editTitle: string;
        mechanicLabel: string;
        mechanics: {
            percent: {
                title: string;
                description: string;
            };
            fixed: {
                title: string;
                description: string;
            };
            nth_item: {
                title: string;
                description: string;
            };
            bundle: {
                title: string;
                description: string;
            };
        };
        nameArLabel: string;
        nameArPlaceholder: string;
        nameEnLabel: string;
        nameEnPlaceholder: string;
        badgeArLabel: string;
        badgeArPlaceholder: string;
        badgeArHelp: string;
        badgeEnLabel: string;
        badgeEnPlaceholder: string;
        badgeEnHelp: string;
        scopeLabel: string;
        scopeAll: string;
        scopeCategory: string;
        scopeService: string;
        categoryLabel: string;
        categoryPlaceholder: string;
        serviceTypeLabel: string;
        serviceTypePlaceholder: string;
        typeLabel: string;
        typePercent: string;
        typeFixed: string;
        valueLabel: string;
        valuePercentHelp: string;
        valueFixedHelp: string;
        buyQuantityLabel: string;
        buyQuantityHelp: string;
        getQuantityLabel: string;
        getQuantityHelp: string;
        qualifyingScopeLabel: string;
        qualifyingScopes: {
            same_product: string;
            same_category: string;
            same_service: string;
            any: string;
        };
        maxApplicationsLabel: string;
        maxApplicationsPlaceholder: string;
        maxApplicationsHelp: string;
        discountTargetLabel: string;
        discountTargetCheapest: string;
        discountTargetMostExpensive: string;
        discountTargetHelp: string;
        bundlePriceLabel: string;
        bundlePricePlaceholder: string;
        bundlePriceHelp: string;
        componentsLabel: string;
        addComponentButton: string;
        removeComponentButton: string;
        selectProductPlaceholder: string;
        quantityLabel: string;
        componentsMinError: string;
        totalPartsPrice: string;
        bundleSaving: string;
        bundleSavingHelp: string;
        appliesToPromotedLabel: string;
        appliesToPromotedHelp: string;
        startsAtLabel: string;
        endsAtLabel: string;
        isActiveLabel: string;
        saveButton: string;
        savingButton: string;
        cancelButton: string;
        columns: {
            name: string;
            mechanic: string;
            terms: string;
            scope: string;
            discount: string;
            window: string;
            status: string;
            actions: string;
        };
        scopeAllBadge: string;
        scopeCategoryBadge: string;
        scopeServiceBadge: string;
        typePercentBadge: string;
        typeFixedBadge: string;
        chips: {
            percent: string;
            fixed: string;
            nth_item: string;
            bundle: string;
        };
        terms: {
            percent: string;
            fixed: string;
            nthItem: string;
            nthItemUnlimited: string;
            bundleSummary: string;
        };
        statusTabs: {
            all: string;
            active: string;
            scheduled: string;
            paused: string;
            ended: string;
        };
        always: string;
        from: string;
        until: string;
        window: string;
        active: string;
        inactive: string;
        endsIn: string;
        noPromotions: string;
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
        apply: string;
        archivedActive: string;
        archivedArchived: string;
        authority: string;
        authorityAutomation: string;
        authorityManual: string;
        activeFilters: string;
        clearAll: string;
        clearOneFilter: string;
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
        categories?: string;
        manageCategories?: string;
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
        // Visibility
        adminHiddenBadge: string;
        automationVisibleBadge: string;
        automationHiddenBadge: string;
        hideFromStore: string;
        hidingFromStore: string;
        restoreToStore: string;
        restoringToStore: string;
        hideDialogTitle: string;
        hideDialogDescription: string;
        confirmHideButton: string;
        restoreDialogTitle: string;
        restoreDialogDescription: string;
        confirmRestoreButton: string;
        visibilityUpdatedTitle: string;
        visibilityHiddenMessage: string;
        visibilityRestoredMessage: string;
        visibilityConflictError: string;
        visibilityUpdateFailed: string;
        storefrontStatus: string;
        storefrontVisible: string;
        storefrontAdminHidden: string;
        automationVisibility: string;
        automationVisible: string;
        automationHidden: string;
        // Pricing override & revert
        effectivePrice: string;
        overrideActiveBadge: string;
        automationPriceLabel: string;
        overridePriceButton: string;
        editOverrideButton: string;
        revertToAutomationButton: string;
        revertingToAutomation: string;
        variantActions: string;
        priceOverrideDialogTitle: string;
        priceOverrideDialogDescription: string;
        repriceWarning: string;
        priceHalalahLabel: string;
        priceHalalahHelp: string;
        saveOverrideButton: string;
        savingOverrideButton: string;
        tierTableTitle: string;
        tierTableDescription: string;
        tierCompletions: string;
        tierDiscount: string;
        tierTotalHalalah: string;
        tierEquivalentSar: string;
        tierCountLabel: string;
        singlePriceTitle: string;
        revertDialogTitle: string;
        revertDialogDescription: string;
        confirmRevertButton: string;
        priceOverrideUpdated: string;
        priceOverrideUpdatedMessage: string;
        priceOverrideCleared: string;
        priceOverrideClearedMessage: string;
        priceConflictError: string;
        priceOverrideFailed: string;
        revertFailed: string;
        positivePriceRequired: string;
        firstTierMustMatchPrice: string;
    };
    conversations: {
        actions: string;
        allLocales: string;
        allOwners: string;
        allStatuses: string;
        clearSearch: string;
        columns: string;
        conversation: string;
        createdAt: string;
        customer: string;
        description: string;
        errorTitle: string;
        filterLocale: string;
        filterOwner: string;
        filterStatus: string;
        filters: string;
        firstPage: string;
        headTitle: string;
        lastActivity: string;
        lastPage: string;
        loadFailed: string;
        loading: string;
        locale: string;
        localeAr: string;
        localeEn: string;
        messageCount: string;
        next: string;
        noConversations: string;
        noConversationsMatching: string;
        of: string;
        owner: string;
        ownerCustomer: string;
        allTicketStatuses: string;
        filterTicketStatus: string;
        ticketOpen: string;
        ticketResolved: string;
        ticketClosed: string;
        ticket: string;
        chat: string;
        unreadDot: string;
        guestNotice: string;
        ownerGuest: string;
        page: string;
        perPage: string;
        previous: string;
        resetFilters: string;
        results: string;
        searchButton: string;
        searchLabel: string;
        searchPlaceholder: string;
        showing: string;
        sortAscending: string;
        sortBy: string;
        sortDescending: string;
        status: string;
        statusClosed: string;
        statusOpen: string;
        tableLabel: string;
        title: string;
        to: string;
        toggleColumns: string;
        viewDetail: string;
    };
    conversationDetail: {
        assistantSender: string;
        backToConversations: string;
        closeReason: string;
        closeReasons: Record<string, string>;
        closedAt: string;
        createdAt: string;
        customerName: string;
        customerSender: string;
        guestSender: string;
        headTitle: string;
        inputTokens: string;
        lastMessageAt: string;
        latency: string;
        locale: string;
        messageCount: string;
        model: string;
        noMessages: string;
        noTurns: string;
        outputTokens: string;
        owner: string;
        promptVersion: string;
        publicId: string;
        runStatus: string;
        status: string;
        summarySection: string;
        systemSender: string;
        title: string;
        tokens: string;
        transcriptSection: string;
        replySection: string;
        replyToCustomer: string;
        internalNoteLabel: string;
        replyPlaceholder: string;
        notePlaceholder: string;
        sendReply: string;
        saveNote: string;
        resolveTicket: string;
        takeOver: string;
        staffSender: string;
        replyTakesOverNotice: string;
        noteNotice: string;
        ticketSection: string;
        turnCreatedAt: string;
        turnId: string;
        turnStatus: string;
        turnsSection: string;
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
    categories: {
        invalidPassword: string;
        passwordLabel: string;
        passwordModalDescription: string;
        passwordModalTitle: string;
        passwordPlaceholder: string;
        actions: string;
        activeFilters: string;
        allSources: string;
        allVisibilities: string;
        apply: string;
        authorityAutomation: string;
        authorityManual: string;
        automationHiddenBadge: string;
        automationVisibleBadge: string;
        backToProducts: string;
        cancelButton: string;
        clearAll: string;
        clearOneFilter: string;
        clearSearch: string;
        columns: string;
        confirmHideButton: string;
        confirmRestoreButton: string;
        createdAt: string;
        description: string;
        errorTitle: string;
        filterSource: string;
        filterVisibility: string;
        filters: string;
        firstPage: string;
        headTitle: string;
        hiddenAt: string;
        hideDialogDescription: string;
        hideDialogTitle: string;
        hideFromStore: string;
        hidingFromStore: string;
        lastPage: string;
        loadFailed: string;
        loading: string;
        name: string;
        next: string;
        noCategories: string;
        noCategoriesMatching: string;
        of: string;
        page: string;
        perPage: string;
        previous: string;
        products: string;
        productsCount: string;
        resetFilters: string;
        restoreDialogDescription: string;
        restoreDialogTitle: string;
        restoreToStore: string;
        restoringToStore: string;
        results: string;
        searchButton: string;
        searchLabel: string;
        searchPlaceholder: string;
        selectAll: string;
        selectRow: string;
        selectedRows: string;
        showing: string;
        slug: string;
        sortAscending: string;
        sortBy: string;
        sortDescending: string;
        sortOrder: string;
        source: string;
        sourceAutomation: string;
        sourceManual: string;
        stateAdminHidden: string;
        stateAutomationHidden: string;
        stateVisible: string;
        status: string;
        tableLabel: string;
        title: string;
        to: string;
        toggleColumns: string;
        updatedAt: string;
        viewProducts: string;
        visibilityAdminHidden: string;
        visibilityAutomationHidden: string;
        visibilityConflictError: string;
        visibilityHiddenMessage: string;
        visibilityRestoredMessage: string;
        visibilityUpdateFailed: string;
        visibilityVisible: string;
        visibleProducts: string;
        visibleProductsCount: string;
    };
};

export type AdminNavigationChild = {
    key:
        | 'products'
        | 'categories'
        | 'marketingCoupons'
        | 'marketingPromotions'
        | 'marketingLoyalty';
    label: string;
    url: string;
};

export type AdminNavigationItem = {
    key:
        | 'overview'
        | 'orders'
        | 'customers'
        | 'conversations'
        | 'catalog'
        | 'products'
        | 'marketing'
        | 'marketingLoyalty'
        | 'settings'
        | 'more';
    label: string;
    url: string;
    children?: AdminNavigationChild[];
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
        rangeDays: 1 | 7 | 30;
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
        recentOrders: AdminRecentOrder[];
        oldestUnresolvedOrder: null | {
            id: string;
            number: string;
            status: string;
            placedAt: string;
        };
    };
    rangeOptions: Array<{
        days: 1 | 7 | 30;
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
    logoutUrl: string;
};

export type AdminCouponTargetSummary = {
    id: string;
    targetType: string;
    targetId: number;
    name: string;
};

export type AdminCouponRow = {
    id: string;
    code: string;
    descriptionAr: string | null;
    descriptionEn: string | null;
    discountType: 'percent' | 'fixed';
    value: number;
    minimumOrderHalalah: number;
    maximumDiscountHalalah: number | null;
    usageLimit: number | null;
    perUserLimit: number | null;
    usedCount: number;
    scope: 'order' | 'category' | 'product' | 'service';
    serviceType: string | null;
    firstOrderOnly: boolean;
    excludesPromotedItems: boolean;
    startsAt: string | null;
    endsAt: string | null;
    isActive: boolean;
    status: 'active' | 'scheduled' | 'paused' | 'expired' | 'exhausted';
    targets: AdminCouponTargetSummary[];
    categoryIds: number[];
    productIds: number[];
    createdAt: string;
};

export type AdminCouponsQueryState = {
    search?: string | null;
    status?:
        | 'all'
        | 'active'
        | 'scheduled'
        | 'paused'
        | 'expired'
        | 'exhausted'
        | null;
    scope?: 'order' | 'category' | 'product' | 'service' | null;
    discount_type?: 'percent' | 'fixed' | null;
    sort?: 'created_at' | 'code' | 'used_count' | 'value';
    direction?: 'asc' | 'desc';
    per_page?: 15 | 25 | 50 | 100;
    page?: number;
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
        scheduled?: number;
        paused?: number;
        expired?: number;
        exhausted?: number;
    };
    filters: AdminCouponsQueryState;
    filterOptions: {
        statuses: AdminFilterOption[];
        scopes: AdminFilterOption[];
        discountTypes: AdminFilterOption[];
        perPageOptions: number[];
    };
    categories: Array<{ id: number; publicId: string; name: string }>;
    products: Array<{ id: number; publicId: string; name: string }>;
    serviceTypes: Array<{ value: string; label: string }>;
    createUrl: string;
    updateUrlTemplate: string;
    statusUrlTemplate: string;
    duplicateUrlTemplate: string;
    showUrlTemplate: string;
    logoutUrl: string;
};

export type AdminCouponDetail = AdminCouponRow;

export type AdminCouponKpis = {
    usedCount: number;
    usageLimit: number | null;
    uniqueCustomers: number;
    revenueAttributed: AdminMoney<'SAR'>;
    totalDiscountGiven: AdminMoney<'SAR'>;
    totalRedemptions: number;
    releasedRedemptionsCount: number;
};

export type AdminCouponRuleItem = {
    key: string;
    label: string;
    value: string;
    description?: string;
};

export type AdminCouponChartPoint = {
    date: string;
    redemptions: number;
    revenueHalalah: number;
    discountHalalah: number;
};

export type AdminCouponRecentRedemption = {
    id: string;
    orderId: string;
    orderNumber: string;
    orderStatus: string;
    isPaid: boolean;
    paidAt: string | null;
    orderTotal: AdminMoney<'SAR'>;
    discount: AdminMoney<'SAR'>;
    customer: {
        id: string;
        name: string;
        email: string;
    };
    redeemedAt: string;
};

export type AdminCouponDetailPageProps = {
    locale: 'ar' | 'en';
    direction: 'rtl' | 'ltr';
    adminUi: AdminTranslations;
    adminIdentity: AdminIdentity;
    adminNavigation: AdminNavigationItem[];
    permissions: string[];
    coupon: AdminCouponDetail;
    kpis: AdminCouponKpis;
    rules: AdminCouponRuleItem[];
    chart: AdminCouponChartPoint[];
    recentRedemptions: AdminCouponRecentRedemption[];
    categories: Array<{ id: number; publicId: string; name: string }>;
    products: Array<{ id: number; publicId: string; name: string }>;
    serviceTypes: Array<{ value: string; label: string }>;
    updateUrl: string;
    statusUrl: string;
    duplicateUrl: string;
    listUrl: string;
    logoutUrl: string;
};

export type AdminPromotionComponentRow = {
    id: string;
    productId: string;
    productName: string;
    quantity: number;
};

export type AdminPromotionRow = {
    id: string;
    nameAr: string;
    nameEn: string;
    badgeAr: string | null;
    badgeEn: string | null;
    mechanic: 'item' | 'nth_item' | 'bundle';
    scope: 'all' | 'category' | 'service';
    categoryName: string | null;
    categoryId: string | null;
    serviceType: string | null;
    discountType: 'percent' | 'fixed';
    value: number;
    buyQuantity: number | null;
    getQuantity: number | null;
    maxApplications: number | null;
    discountTarget: 'cheapest' | 'most_expensive' | null;
    qualifyingScope:
        'same_product' | 'same_category' | 'same_service' | 'any' | null;
    bundlePriceHalalah: number | null;
    appliesToPromotedItems: boolean;
    components: AdminPromotionComponentRow[];
    startsAt: string | null;
    endsAt: string | null;
    isActive: boolean;
    createdAt: string;
};

export type AdminPromotionsQueryState = {
    search?: string | null;
    status?: 'all' | 'active' | 'scheduled' | 'paused' | 'ended' | null;
    sort: 'created_at' | 'name' | 'value';
    direction: 'asc' | 'desc';
    per_page: 15 | 25 | 50;
    page: number;
};

export type AdminPromotionProductOption = {
    id: string;
    name: string;
    priceHalalah: number;
};

export type AdminPromotionsPageProps = {
    locale: 'ar' | 'en';
    direction: 'rtl' | 'ltr';
    adminUi: AdminTranslations;
    adminIdentity: AdminIdentity;
    adminNavigation: AdminNavigationItem[];
    permissions: string[];
    promotions: AdminPromotionRow[];
    pagination: AdminPagination;
    counts: {
        total: number;
        active: number;
        scheduled?: number;
        paused?: number;
        ended?: number;
    };
    categories: Array<{ id: string; name: string }>;
    products: AdminPromotionProductOption[];
    createUrl: string;
    updateUrlTemplate: string;
    statusUrlTemplate: string;
    filters: AdminPromotionsQueryState;
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
    categoriesUrl?: string;
    logoutUrl: string;
};

export type SbcCompletionPricingTier = {
    completions: number;
    multiplierBps: number;
    totalMinor: number;
};

export type SbcCompletionPricing = {
    version: 1;
    repeatable: boolean;
    maximum: number | null;
    tiers: SbcCompletionPricingTier[];
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
    adminPriceHalalah?: number | null;
    adminCompletionPricing?: SbcCompletionPricing | null;
    effectivePriceHalalah?: number;
    hasOverride?: boolean;
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
    adminHidden?: boolean;
    adminHiddenAt?: string | null;
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
    visibilityUrl: string;
    variantPriceUrlTemplate: string;
    logoutUrl: string;
};

export type AdminSupportTicketStatus = 'open' | 'resolved' | 'closed';

export type AdminConversationRow = {
    publicId: string;
    shortId: string;
    ticketNumber: string | null;
    ticketStatus: AdminSupportTicketStatus | null;
    hasUnread: boolean;
    status: 'open' | 'closed' | 'archived';
    locale: string;
    ownerType: 'guest' | 'customer';
    customerName: string | null;
    messageCount: number;
    lastMessageAt: string | null;
    createdAt: string;
};

export type AdminConversationsQueryState = {
    q?: string | null;
    status?: 'open' | 'closed' | null;
    locale?: 'ar' | 'en' | null;
    owner?: 'guest' | 'customer' | null;
    ticket_status?: AdminSupportTicketStatus | null;
    per_page: 15 | 25 | 50 | 100;
    page: number;
};

export type AdminConversationsPageProps = {
    locale: 'ar' | 'en';
    direction: 'rtl' | 'ltr';
    adminUi: AdminTranslations;
    adminIdentity: AdminIdentity;
    adminNavigation: AdminNavigationItem[];
    permissions: string[];
    rows: AdminConversationRow[];
    pagination: AdminPagination;
    filters: AdminConversationsQueryState;
    filterOptions: {
        statuses: AdminFilterOption[];
        locales: AdminFilterOption[];
        ticketStatuses: AdminFilterOption[];
        perPageOptions: number[];
    };
    logoutUrl: string;
};

export type AdminChatMessage = {
    publicId: string;
    senderType: 'customer' | 'assistant' | 'system' | 'staff';
    messageType: 'text' | 'system' | 'internal_note';
    content: string;
    staffName: string | null;
    createdAt: string;
};

export type AdminSupportTicket = {
    publicId: string;
    ticketNumber: string;
    status: AdminSupportTicketStatus;
    subject: string | null;
    assignedAdminName: string | null;
    assignedToMe: boolean;
    openedAt: string | null;
};

export type AdminAgentTurn = {
    publicId: string;
    status: 'waiting' | 'running' | 'completed' | 'failed' | 'cancelled';
    promptVersion: string;
    createdAt: string;
    latestRunStatus: 'running' | 'completed' | 'failed' | 'cancelled' | null;
    latencyMs: number | null;
    inputTokens: number | null;
    outputTokens: number | null;
    model: string | null;
};

export type AdminConversationDetail = {
    publicId: string;
    shortId: string;
    handoffState: 'none' | 'offered' | 'requested' | 'active' | 'resolved';
    status: 'open' | 'closed' | 'archived';
    locale: string;
    ownerType: 'guest' | 'customer';
    customerName: string | null;
    messageCount: number;
    lastMessageAt: string | null;
    createdAt: string;
    closedAt: string | null;
    closeReason: string | null;
};

export type AdminConversationDetailPageProps = {
    locale: 'ar' | 'en';
    direction: 'rtl' | 'ltr';
    adminUi: AdminTranslations;
    adminIdentity: AdminIdentity;
    adminNavigation: AdminNavigationItem[];
    permissions: string[];
    conversation: AdminConversationDetail;
    ticket: AdminSupportTicket | null;
    canReply: boolean;
    messages: AdminChatMessage[];
    turns: AdminAgentTurn[];
    logoutUrl: string;
};

export type AdminCategoryRow = {
    id: string;
    slug: string;
    name: string;
    nameAr: string;
    nameEn: string;
    descriptionAr: string | null;
    descriptionEn: string | null;
    source: { name: string; key: string } | null;
    isAutomation: boolean;
    isVisible: boolean;
    adminHidden: boolean;
    adminHiddenAt: string | null;
    sortOrder: number;
    productsCount: number;
    visibleProductsCount: number;
    createdAt: string;
    updatedAt: string;
};

export type AdminCategoriesQueryState = {
    search?: string | null;
    visibility?: 'visible' | 'admin_hidden' | 'automation_hidden' | null;
    source?: string | null;
    sort?: 'sort_order' | 'name' | 'created_at' | 'updated_at';
    direction?: 'asc' | 'desc';
    per_page?: 15 | 25 | 50 | 100;
    page?: number;
};

export type AdminMoreTile = {
    key:
        | 'conversations'
        | 'categories'
        | 'coupons'
        | 'promotions'
        | 'loyalty'
        | 'settings';
    label: string;
    description: string;
    url: string;
};

export type AdminMoreGroup = {
    key: 'catalog' | 'marketing' | 'system';
    label: string;
    tiles: AdminMoreTile[];
};

export type AdminCategoriesPageProps = {
    locale: 'ar' | 'en';
    direction: 'rtl' | 'ltr';
    adminUi: AdminTranslations;
    adminIdentity: AdminIdentity;
    adminNavigation: AdminNavigationItem[];
    permissions: string[];
    categories: AdminCategoryRow[];
    pagination: AdminPagination;
    filters: AdminCategoriesQueryState;
    filterOptions: {
        visibilities: AdminFilterOption[];
        sources: AdminFilterOption[];
        perPageOptions: number[];
    };
    productsUrl?: string;
    visibilityUrlTemplate: string;
    logoutUrl: string;
};

export type AdminMorePageProps = {
    locale: 'ar' | 'en';
    direction: 'rtl' | 'ltr';
    adminUi: AdminTranslations;
    adminIdentity: AdminIdentity;
    adminNavigation: AdminNavigationItem[];
    permissions: string[];
    groups: AdminMoreGroup[];
    logoutUrl: string;
};
