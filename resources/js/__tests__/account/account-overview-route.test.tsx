import {
    cleanup,
    fireEvent,
    render,
    screen,
    within,
} from '@testing-library/react';
import { afterEach, expect, it, vi } from 'vitest';

import AccountOverview from '@/pages/account/overview';

const mockPage = vi.hoisted(() => ({
    props: {
        accountUi: {
            page_title: 'حسابي',
            eyebrow: 'حساب عرب التيميت',
            greeting: 'مرحبًا، :name',
            introduction: 'تابع طلباتك ورصيدك وبيانات حسابك من مكان واحد.',
            navigation: {
                label: 'أقسام حسابي',
                overview: 'نظرة عامة',
                orders: 'طلباتي',
                wallet: 'محفظتي',
                profile: 'بياناتي',
                security: 'الأمان',
                support: 'الدعم',
                logout: 'تسجيل الخروج',
            },
            overview: {
                title: 'نظرة عامة',
                description: 'ملخص سريع لحسابك وآخر نشاط لك.',
                orders_metric: 'الطلبات',
                open_orders_metric: 'طلبات قيد التنفيذ',
                completed_orders_metric: 'طلبات مكتملة',
                wallet_metric: 'رصيد المحفظة',
                active_order: 'طلب يحتاج متابعتك',
                recent_orders: 'أحدث الطلبات',
                loyalty: 'تقدم الولاء',
                empty_title: 'كل شيء جاهز لأول طلب لك',
                empty_description: 'لم تنشئ أي طلب بعد.',
                browse_services: 'تصفح الخدمات',
                loyalty_remaining: 'تبقى :amount للوصول إلى فئة :tier.',
                loyalty_complete: 'وصلت إلى أعلى فئة ولاء متاحة.',
            },
            statuses: {
                pending_payment: 'بانتظار الدفع',
                received: 'تم استلام الدفع',
                in_progress: 'قيد التنفيذ',
                waiting_for_customer: 'بانتظارك',
                completed: 'مكتمل',
                cancelled: 'ملغي',
                refunded: 'مسترد',
                failed: 'يحتاج مراجعة',
            },
            actions: {
                view_order: 'عرض الطلب',
                view_all: 'عرض الكل',
                pay_now: 'إكمال الدفع',
                retry_payment: 'إعادة محاولة الدفع',
                provide_details: 'استكمال البيانات',
                retry: 'إعادة المحاولة',
                back_to_account: 'العودة إلى حسابي',
            },
            accessibility: {
                current_page: 'الصفحة الحالية',
                open_navigation: 'فتح قائمة الحساب',
                close_navigation: 'إغلاق قائمة الحساب',
                order_status: 'حالة الطلب: :status',
            },
        },
        accountIdentity: {
            name: 'محمد لاعب',
            greeting: 'مرحبًا، محمد لاعب',
        },
        accountNavigation: [
            { key: 'overview', label: 'نظرة عامة', url: '/my-account' },
        ],
        activeOrder: {
            id: '01ACTIVE',
            source: 'live',
            number: 'UT-10000001',
            status: 'waiting_for_customer',
            placedAt: '2026-08-15T10:00:00+00:00',
            summary: 'خدمة كوينز FC 27',
            itemCount: 1,
            total: { amountMinor: '12999', currency: 'SAR' },
            detailUrl: '/orders/01ACTIVE',
            action: { type: 'provide_details' },
        },
        cartCount: 0,
        direction: 'rtl',
        displayCurrency: 'SAR',
        displayCurrencies: ['SAR', 'USD'],
        locale: 'ar',
        loyalty: {
            eligibleSpend: { amountMinor: '18000', currency: 'SAR' },
            currentTier: {
                key: 'bronze',
                name: 'برونزي',
                minimum: { amountMinor: '0', currency: 'SAR' },
            },
            nextTier: {
                key: 'gold',
                name: 'ذهبي',
                minimum: { amountMinor: '20000', currency: 'SAR' },
            },
            remaining: { amountMinor: '2000', currency: 'SAR' },
            progressPercent: 90,
        },
        logoutUrl: '/logout',
        recentOrders: [
            {
                id: '01RECENT',
                source: 'live',
                number: 'UT-10000000',
                status: 'completed',
                placedAt: '2026-08-14T10:00:00+00:00',
                summary: 'خدمة SBC',
                itemCount: 1,
                total: { amountMinor: '98765', currency: 'SAR' },
                detailUrl: '/orders/01RECENT',
            },
        ],
        storeShell: {
            homeUrl: '/',
            coinsUrl: '/#coins',
            cartUrl: '/cart',
            sbcUrl: '/sbc',
            futChampionsUrl: '/fut-champions',
            accountUrl: '/my-account',
            privacyUrl: '/privacy',
            returnsUrl: '/returns',
            warrantyUrl: '/warranty',
            eaBackupCodesUrl: '/ea-backup-codes',
            termsUrl: '/terms',
            whatsappUrl: 'https://wa.me/966537998099',
            email: 'info@arab-ut.com',
            socials: { x: '', instagram: '' },
            payments: [],
        },
        ui: {
            brand: 'عرب ألتميت',
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
        summary: {
            orderCount: 2,
            openOrderCount: 1,
            completedOrderCount: 1,
            walletBalance: { amountMinor: '2500', currency: 'SAR' },
        },
    },
    url: '/my-account',
}));

vi.mock('@inertiajs/react', () => ({
    Head: ({ title }: { title: string }) => <title>{title}</title>,
    Link: ({ children, href, ...props }: React.ComponentProps<'a'>) => (
        <a href={typeof href === 'string' ? href : ''} {...props}>
            {children}
        </a>
    ),
    router: {
        flushAll: vi.fn(),
        post: vi.fn(),
    },
    usePage: () => mockPage,
}));

afterEach(cleanup);

it('uses the canonical Arabic customer account identity inside the storefront shell', () => {
    render(<AccountOverview />);

    expect(
        screen.getByRole('heading', { level: 1, name: 'أهلًا، محمد' }),
    ).toBeVisible();
    expect(
        screen.getAllByRole('navigation', { name: 'أقسام حسابي' })[0],
    ).toBeVisible();
    expect(
        screen.getAllByRole('link', { name: 'نظرة عامة' })[0],
    ).toHaveAttribute('aria-current', 'page');
    expect(screen.getByRole('button', { name: 'تسجيل الخروج' })).toBeVisible();
    expect(screen.getByTitle('UT-10000001')).toBeVisible();
    expect(screen.getByText('خدمة كوينز FC 27')).toBeVisible();
    expect(screen.getAllByText('رصيد المحفظة')[0]).toBeVisible();
    expect(screen.getByText('تقدم الولاء')).toBeVisible();
    expect(screen.getByText('90%')).toBeVisible();

    const recentOrders = screen.getByRole('region', { name: 'أحدث الطلبات' });

    expect(within(recentOrders).getByTitle('UT-10000000')).toBeVisible();
    expect(within(recentOrders).getByText('خدمة SBC')).toBeVisible();
    expect(
        document.documentElement.querySelector('[lang="ar"]'),
    ).toHaveAttribute('dir', 'rtl');
    expect(document.title).toBe('حسابي');
    expect(screen.queryByText('Dashboard')).not.toBeInTheDocument();

    fireEvent.click(screen.getByRole('button', { name: 'تسجيل الخروج' }));
});
