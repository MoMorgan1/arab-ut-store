import { cleanup, render, screen, within } from '@testing-library/react';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';

import AccountLoyalty from '@/pages/account/loyalty';
import type { AccountLoyaltyPageProps } from '@/types/account';

const page = vi.hoisted(() => ({
    props: {} as AccountLoyaltyPageProps,
    url: '/en/my-account/loyalty',
}));

vi.mock('@inertiajs/react', () => ({
    Head: ({ title }: { title: string }) => <title>{title}</title>,
    Link: ({ children, href, className }: React.ComponentProps<'a'>) => (
        <a className={className} href={typeof href === 'string' ? href : ''}>
            {children}
        </a>
    ),
    usePage: () => page,
}));

vi.mock('@/layouts/my-account-layout', () => ({
    default: ({ children }: React.PropsWithChildren) => <main>{children}</main>,
}));

beforeEach(() => {
    page.props = loyaltyProps();
});

afterEach(cleanup);

it('renders tiers, highlights current tier, hero card, progress, rules, and cashback history', () => {
    render(<AccountLoyalty />);

    expect(
        screen.getByRole('heading', { level: 2, name: 'Loyalty Programme' }),
    ).toBeVisible();

    const backLink = screen.getByRole('link', { name: 'Back to Overview' });
    expect(backLink).toHaveAttribute('href', '/en/my-account');

    // Hero card
    expect(screen.getByText('Current Tier')).toBeVisible();
    expect(screen.getAllByText('Silver')[0]).toBeVisible();
    expect(screen.getByText('33%')).toBeVisible();
    expect(
        screen.getByRole('progressbar', { name: 'Loyalty Programme' }),
    ).toHaveAttribute('aria-valuenow', '33');
    expect(
        screen.getByText(/100\.00.*remaining to reach Gold\./),
    ).toBeVisible();
    expect(screen.getByText(/150\.00/)).toBeVisible(); // eligible spend
    expect(screen.getByText(/40\.00/)).toBeVisible(); // lifetime cashback

    // 4-tier table
    const table = screen.getByRole('table');
    expect(within(table).getByText('Bronze')).toBeVisible();
    expect(within(table).getByText('1%')).toBeVisible();
    expect(within(table).getByText('Silver')).toBeVisible();
    expect(within(table).getByText('2%')).toBeVisible();
    expect(within(table).getByText('Your current tier')).toBeVisible();
    expect(within(table).getByText('Gold')).toBeVisible();
    expect(within(table).getByText('3%')).toBeVisible();
    expect(within(table).getByText('Platinum')).toBeVisible();
    expect(within(table).getByText('5%')).toBeVisible();

    const silverRow = within(table).getByText('Silver').closest('tr');
    expect(silverRow).toHaveAttribute('data-current', 'true');

    // How it works
    expect(screen.getByText('How it works')).toBeVisible();
    expect(
        screen.getByText(
            'Calculated on the net amount paid after discounts and wallet balance',
        ),
    ).toBeVisible();
    expect(
        screen.getByText('Added to your wallet once the order is completed'),
    ).toBeVisible();
    expect(screen.getByText('Reversed if the order is refunded')).toBeVisible();

    // Recent cashback
    expect(screen.getByText('Recent Cashback')).toBeVisible();
    expect(screen.getByText('Cashback')).toBeVisible();
    expect(screen.getByText(/\+SAR\s50\.00/)).toBeVisible();
    expect(screen.getByText('Cashback reversal')).toBeVisible();
    expect(screen.getByText(/−SAR\s10\.00/)).toBeVisible();
    expect(
        screen.getByRole('link', { name: 'Order UT-12345678' }),
    ).toHaveAttribute(
        'href',
        '/en/my-account/orders/01K00000000000000000000000',
    );
});

it('renders completion copy when on highest tier', () => {
    page.props = {
        ...loyaltyProps(),
        currentTier: {
            key: 'platinum',
            name: 'Platinum',
            minimum: { amountMinor: '50000', currency: 'SAR' },
        },
        nextTier: null,
        remaining: null,
        progressPercent: 100,
    };

    render(<AccountLoyalty />);

    expect(
        screen.getByText(
            'You have reached the highest available loyalty tier.',
        ),
    ).toBeVisible();
    expect(screen.getByText('100%')).toBeVisible();
});

it('renders empty tiers state safely without crashing when no tiers exist', () => {
    page.props = {
        ...loyaltyProps(),
        tiers: [],
        currentTier: null,
        nextTier: null,
        remaining: null,
        progressPercent: 0,
        eligibleSpend: { amountMinor: '0', currency: 'SAR' },
        cashback: {
            lifetime: { amountMinor: '0', currency: 'SAR' },
            entries: [],
        },
    };

    render(<AccountLoyalty />);

    expect(
        screen.getByText('Loyalty programme is not available currently'),
    ).toBeVisible();
    expect(
        screen.getByText(
            'We will announce loyalty programme details and cashback rewards soon.',
        ),
    ).toBeVisible();
    expect(screen.queryByRole('table')).not.toBeInTheDocument();
});

function loyaltyProps(): AccountLoyaltyPageProps {
    return {
        direction: 'ltr',
        displayCurrencies: ['SAR'],
        displayCurrency: 'SAR',
        cartCount: 0,
        locale: 'en',
        accountIdentity: {
            name: 'Mohamed Player',
            greeting: 'Hello, Mohamed Player',
        },
        accountNavigation: [
            { key: 'overview', label: 'Overview', url: '/en/my-account' },
            { key: 'orders', label: 'Orders', url: '/en/my-account/orders' },
            { key: 'wallet', label: 'Wallet', url: '/en/my-account/wallet' },
            { key: 'profile', label: 'Profile', url: '/en/my-account/profile' },
        ],
        logoutUrl: '/logout',
        storeShell: {
            coinsUrl: '/coins',
            homeUrl: '/',
            cartUrl: '/cart',
            sbcUrl: '/sbc',
            futChampionsUrl: '/fut-champions',
            accountUrl: '/en/my-account',
            privacyUrl: '/privacy',
            returnsUrl: '/returns',
            warrantyUrl: '/warranty',
            eaBackupCodesUrl: '/ea-backup-codes',
            termsUrl: '/terms',
            whatsappUrl: 'https://wa.me/1',
            email: 'support@example.test',
            socials: { x: '', instagram: '' },
            payments: [],
        },
        ui: {
            brand: 'عرب ألتميت',
            home_title: 'Home',
            cart_added: {
                title: 'تمت الإضافة',
                message: 'أضيفت الخدمة.',
                buy_now: 'إتمام الشراء',
                continue_shopping: 'متابعة التسوق',
            },
            currency_selector: 'اختر العملة',
            language: 'English',
            skip_to_content: 'تخطي إلى المحتوى',
            store_tools: 'أدوات المتجر',
            header: {
                primary_navigation: 'التنقل الرئيسي',
                preferences: 'التفضيلات',
                home: 'الرئيسية',
                coins: 'كوينز',
                sbc: 'SBC',
                fut_champions: 'FUT Champions',
                most_requested: 'الأكثر طلبًا',
                whatsapp: 'واتساب',
                cart: 'السلة',
                account: 'حسابي',
            },
            preferences: { exchange_rate_attribution: 'Rates provider' },
            footer: {
                description: 'خدمات موثوقة.',
                important_links: 'روابط مهمة',
                privacy: 'الخصوصية',
                returns: 'الاسترجاع',
                warranty: 'الضمان',
                ea_backup_codes: 'أكواد EA',
                terms: 'الشروط',
                customer_service: 'خدمة العملاء',
                whatsapp: 'واتساب',
                payment_methods: 'طرق الدفع',
                copyright: 'الحقوق محفوظة.',
                ea_disclaimer: 'متجر مستقل.',
            },
        },
        accountUi: {
            page_title: 'My Account',
            eyebrow: 'Arab UT account',
            greeting: 'Hello',
            introduction: 'Overview',
            navigation: {
                label: 'Navigation',
                overview: 'Overview',
                orders: 'Orders',
                wallet: 'Wallet',
                profile: 'Profile',
                security: 'Security',
                support: 'Support',
                logout: 'Logout',
            },
            overview: {
                title: 'Overview',
                description: 'Summary',
                orders_metric: 'Orders',
                open_orders_metric: 'Open',
                completed_orders_metric: 'Completed',
                wallet_metric: 'Wallet',
                active_order: 'Active order',
                recent_orders: 'Recent orders',
                loyalty: 'Loyalty progress',
                empty_title: 'Empty',
                empty_description: 'Empty desc',
                browse_services: 'Browse',
                loyalty_remaining: ':amount remaining to reach :tier.',
                loyalty_complete: 'Highest tier reached.',
            },
            orders: {
                title: 'Orders',
                description: 'Orders desc',
                all: 'All',
                open: 'Open',
                completed: 'Completed',
                empty_title: 'No orders',
                empty_description: 'No orders yet',
                search_placeholder: 'Search by order number or service name',
                search_label: 'Search orders',
                search_empty: 'No orders match your search.',
                columns: {
                    service: 'Service',
                    status: 'Status',
                    total: 'Total',
                },
                number: 'Order number',
                placed_at: 'Placed on',
                total: 'Total',
                discount: 'Discount',
                status: 'Status',
                source_live: 'Current order',
                source_archive: 'Previous order',
                filters_label: 'Filter orders',
                previous: 'Previous',
                next: 'Next',
                pagination: 'Order pages',
                page_status: 'Page :current of :total',
                showing: 'Showing :shown of :total orders',
                items_title: 'Service details',
                item_quantity: 'Quantity: :count',
                credentials_ready: 'Fulfilment details stored securely',
                manual_details: 'Manual service details',
                platform: 'Platform',
                platform_playstation: 'PlayStation',
                platform_pc: 'PC',
                launcher: 'Launcher',
                launcher_ea_app: 'EA App',
                launcher_steam: 'Steam',
                rank: 'Target rank',
                rank_value: 'Rank :rank',
                urgent: 'Delivery',
                urgent_yes: 'Urgent — 24–36 hours',
                urgent_no: 'Standard',
                matches_played: 'Matches already played',
                from_division: 'Current division',
                to_division: 'Target division',
                elite: 'Elite',
                show_credentials: 'Show account details',
                hide_credentials: 'Hide account details',
                credentials_loading: 'Loading account details…',
                credentials_error: 'Could not load account details.',
                squad_image: 'Submitted squad',
                playstation_email: 'PlayStation email',
                playstation_password: 'PlayStation password',
                ea_email: 'EA email',
                ea_password: 'EA password',
                steam_username: 'Steam username',
                steam_password: 'Steam password',
                ea_codes: 'EA backup codes',
                playstation_codes: 'PlayStation backup codes',
                refresh_status: 'Refresh status',
                refreshing: 'Refreshing…',
                back: 'Back to Orders',
                copy: 'Copy',
                copied: 'Copied',
            },
            wallet: {
                title: 'Wallet',
                description: 'Wallet activity',
                available_balance: 'Available balance',
                unavailable_balance: 'Wallet is not active yet',
                lifetime_cashback: 'Cashback earned',
                loyalty_title: 'Loyalty programme',
                ledger_title: 'Wallet activity',
                empty_title: 'No wallet activity yet',
                empty_description: 'No activity yet',
                credit: 'Credit',
                debit: 'Debit',
                refund: 'Refund',
                adjustment: 'Adjustment',
                cashback: 'Cashback',
                cashback_reversal: 'Cashback reversal',
                balance_after: 'Balance after entry',
                related_order: 'Order :number',
                previous: 'Previous',
                next: 'Next',
                pagination: 'Pagination',
                page_status: 'Page :current of :total',
            },
            loyalty: {
                title: 'Loyalty Programme',
                description:
                    'Earn cashback on every order and climb tiers for bigger rewards.',
                hero_badge: 'Current Tier',
                current_tier: ':tier Tier',
                unranked: 'Unranked',
                eligible_spend: 'Eligible spend',
                progress_remaining: ':amount remaining to reach :tier.',
                progress_complete:
                    'You have reached the highest available loyalty tier.',
                back_to_overview: 'Back to Overview',
                table_title: 'Loyalty Tiers & Cashback Rates',
                table_tier: 'Tier',
                table_spend: 'Minimum Spend',
                table_cashback: 'Cashback Rate',
                current_badge: 'Your current tier',
                how_it_works_title: 'How it works',
                how_it_works_1:
                    'Calculated on the net amount paid after discounts and wallet balance',
                how_it_works_2:
                    'Added to your wallet once the order is completed',
                how_it_works_3: 'Reversed if the order is refunded',
                recent_cashback_title: 'Recent Cashback',
                empty_cashback_title: 'No cashback activity yet',
                empty_cashback_desc:
                    'Earn cashback with your first completed order.',
                empty_tiers_title:
                    'Loyalty programme is not available currently',
                empty_tiers_desc:
                    'We will announce loyalty programme details and cashback rewards soon.',
                cashback_percent: ':percent%',
            },
            profile: {
                title: 'Profile',
                description: 'Profile desc',
                personal_title: 'Personal',
                contact_title: 'Contact',
                sections: {
                    label: 'Sections',
                    personal: 'Personal',
                    contact: 'Contact',
                    security: 'Security',
                },
                first_name: 'First name',
                last_name: 'Last name',
                email: 'Email',
                phone: 'Phone',
                preferred_locale: 'Language',
                display_currency: 'Currency',
                save: 'Save',
                saved: 'Saved',
                edit_email: 'Edit email',
                edit_phone: 'Edit phone',
                cancel_edit: 'Cancel',
                new_email: 'New email',
                request_email: 'Send link',
                new_phone: 'New phone',
                send_phone_code: 'Send code',
                phone_code: 'Code',
                confirm_phone: 'Confirm',
                phone_code_sent_to: 'Sent to',
                phone_resend_in: 'Resend in',
                phone_resend: 'Resend',
                phone_change_number: 'Change',
                sensitive_hint: 'Hint',
                pending_email: 'Pending email',
                pending_phone: 'Pending phone',
                email_link_invalid: 'Invalid',
                phone_code_invalid: 'Invalid',
            },
            security: {
                title: 'Security',
                description: 'Security desc',
                current_password: 'Current password',
                new_password: 'New password',
                confirm_password: 'Confirm password',
                change_password: 'Change password',
                set_password: 'Set up password',
                password_changed: 'Password changed',
                social_login_notice: 'Notice',
                change_title: 'Change password',
                setup_title: 'Set up password',
                change_description: 'Change desc',
                setup_description: 'Setup desc',
                recovery_title: 'Recovery',
                recovery_email: 'Recovery email',
                recovery_whatsapp: 'Recovery WhatsApp',
                recovery_action: 'Action',
                reset_link_description: 'Reset desc',
                reset_link_button: 'Send reset link',
                reset_link_sent: 'Sent',
                reset_link_needs_email: 'Needs email',
                reset_link_support: 'Support',
            },
            support: {
                title: 'Support',
                description: 'Support desc',
                whatsapp_title: 'WhatsApp',
                whatsapp_description: 'Chat on WhatsApp',
                whatsapp_action: 'Open WhatsApp',
                email_title: 'Email',
                email_description: 'Email us',
                email_action: 'Send email',
                order_context: 'Order context',
                unavailable_title: 'Unavailable',
                unavailable_description: 'Unavailable desc',
            },
            verification: {
                verified: 'Verified',
                unverified: 'Unverified',
                pending: 'Pending',
                send_code: 'Send code',
                verify: 'Verify',
                code: 'Code',
            },
            statuses: {
                pending_payment: 'Awaiting payment',
                received: 'Payment received',
                in_progress: 'In progress',
                waiting_for_customer: 'Waiting for you',
                completed: 'Completed',
                cancelled: 'Cancelled',
                refunded: 'Refunded',
                failed: 'Needs attention',
            },
            actions: {
                view_order: 'View order',
                view_all: 'View all',
                pay_now: 'Complete payment',
                retry_payment: 'Retry payment',
                provide_details: 'Provide details',
                retry: 'Try again',
                back_to_account: 'Back to My Account',
            },
            accessibility: {
                current_page: 'Current page',
                open_navigation: 'Open navigation',
                close_navigation: 'Close navigation',
                order_status: 'Order status: :status',
            },
            errors: {
                section_title: 'Could not load',
                section_description: 'Try again.',
                save_failed: 'Could not save.',
                unexpected: 'Something unexpected happened. Try again.',
            },
        },
        tiers: [
            {
                key: 'bronze',
                name: 'Bronze',
                minimum: { amountMinor: '0', currency: 'SAR' },
                cashbackPercent: 1,
            },
            {
                key: 'silver',
                name: 'Silver',
                minimum: { amountMinor: '10000', currency: 'SAR' },
                cashbackPercent: 2,
            },
            {
                key: 'gold',
                name: 'Gold',
                minimum: { amountMinor: '25000', currency: 'SAR' },
                cashbackPercent: 3,
            },
            {
                key: 'platinum',
                name: 'Platinum',
                minimum: { amountMinor: '50000', currency: 'SAR' },
                cashbackPercent: 5,
            },
        ],
        currentTier: {
            key: 'silver',
            name: 'Silver',
            minimum: { amountMinor: '10000', currency: 'SAR' },
        },
        nextTier: {
            key: 'gold',
            name: 'Gold',
            minimum: { amountMinor: '25000', currency: 'SAR' },
        },
        remaining: { amountMinor: '10000', currency: 'SAR' },
        progressPercent: 33,
        eligibleSpend: { amountMinor: '15000', currency: 'SAR' },
        cashback: {
            lifetime: { amountMinor: '4000', currency: 'SAR' },
            entries: [
                {
                    id: 'cb-2',
                    sequence: 2,
                    type: 'cashback_reversal',
                    effect: 'debit',
                    amount: { amountMinor: '1000', currency: 'SAR' },
                    createdAt: '2026-08-15T12:00:00+00:00',
                    order: null,
                },
                {
                    id: 'cb-1',
                    sequence: 1,
                    type: 'cashback',
                    effect: 'credit',
                    amount: { amountMinor: '5000', currency: 'SAR' },
                    createdAt: '2026-08-15T11:00:00+00:00',
                    order: {
                        number: 'UT-12345678',
                        url: '/en/my-account/orders/01K00000000000000000000000',
                    },
                },
            ],
        },
    };
}
