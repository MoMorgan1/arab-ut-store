import type { AdminTranslations } from '@/types/admin';

export const englishAdminUi: AdminTranslations = {
    brand: 'Arab UT',
    common: {
        cancel: 'Cancel',
        logout: 'Log out',
        noData: 'No data',
        retry: 'Try again',
    },
    navigation: {
        close: 'Close Admin navigation',
        open: 'Open Admin navigation',
        overview: 'Overview',
        security: 'MFA Security',
    },
    overview: {
        capturedRevenue: 'Captured revenue',
        description:
            'A focused summary of orders and payments that need attention.',
        failedPayments: 'Failed payments',
        failedRefunds: 'Failed refunds',
        headTitle: 'Operational overview',
        inProgressOrders: 'Orders in progress',
        noAudit: 'There is no recent Admin activity.',
        noUnresolved: 'There are no unresolved orders.',
        oldestUnresolved: 'Oldest unresolved order',
        pendingPayments: 'Pending payments',
        range7: 'Last 7 days',
        range30: 'Last 30 days',
        receivedOrders: 'Received orders',
        recentAudit: 'Recent Admin activity',
        title: 'Operations dashboard',
        waitingForCustomer: 'Waiting for customer',
    },
    statuses: {
        authorized: 'Authorized',
        cancelled: 'Cancelled',
        completed: 'Completed',
        failed: 'Failed',
        in_progress: 'In progress',
        paid: 'Paid',
        partially_refunded: 'Partially refunded',
        pending: 'Pending',
        pending_payment: 'Pending payment',
        received: 'Received',
        refunded: 'Refunded',
        waiting_for_customer: 'Waiting for customer',
    },
    mfa: {
        accessDenied:
            'Your account is no longer eligible to manage Admin security.',
        configured: 'Two-factor authentication is on',
        configuredDescription: 'Your account is protected and can enter Admin.',
        confirm: 'Confirm two-factor setup',
        confirmCode: 'Authenticator code',
        confirmPasswordAgain: 'Confirm password',
        confirmRegenerate: 'Replace recovery codes',
        confirming: 'Confirming the code…',
        description:
            'Use an authenticator app to protect your account before entering Admin.',
        enable: 'Start two-factor setup',
        enabling: 'Creating your setup code…',
        eyebrow: 'Admin security',
        failed: 'We could not complete the security request. Your account settings were not changed.',
        headTitle: 'Protect your Admin account',
        hideRecoveryCodes: 'Hide recovery codes',
        invalidCode:
            'That code is invalid or expired. Check your authenticator app and try again.',
        openAccountSecurity: 'Open account security',
        passwordConfirmationExpired:
            'Your password confirmation expired. Confirm it again to continue.',
        qrAlt: 'QR code for adding Arab UT to an authenticator app',
        rateLimited:
            'Too many attempts were sent. Wait one minute before trying again.',
        recoveryTitle: 'Recovery codes',
        recoveryWarning:
            'Keep these codes somewhere safe. Each code can be used only once.',
        regenerateDescription:
            'Your current codes will stop working immediately. Be ready to save the new codes.',
        regenerateRecoveryCodes: 'Create new recovery codes',
        regenerateTitle: 'Replace recovery codes?',
        regenerating: 'Creating new codes…',
        retryAfterWait: 'Reload the page after one minute',
        returnToStore: 'Return to the store',
        scanDescription:
            'Open your authenticator app and scan the code. Then enter the 6-digit code it shows.',
        scanTitle: 'Scan the QR code',
        sessionExpired: 'Your session expired. Sign in again to continue.',
        setupPassword: 'Set a password first',
        setupPasswordDescription:
            'Add a password in account security, then return to finish two-factor setup.',
        showRecoveryCodes: 'Show recovery codes',
        signIn: 'Sign in again',
        startDescription:
            'We will create a QR code for this account. Scan it in your authenticator app, then confirm the code it shows.',
        startTitle: 'Connect your authenticator app',
        title: 'Set up two-factor authentication',
    },
};
