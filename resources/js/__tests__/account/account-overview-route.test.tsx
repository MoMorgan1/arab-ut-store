import { cleanup, render, screen } from '@testing-library/react';
import { afterEach, expect, it, vi } from 'vitest';

import AccountOverview from '@/pages/account/overview';

const mockPage = vi.hoisted(() => ({
    props: {
        accountUi: {
            page_title: 'حسابي',
            eyebrow: 'حساب عرب ألتميت',
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
            },
        },
        cartCount: 0,
        direction: 'rtl',
        displayCurrency: 'SAR',
        displayCurrencies: ['SAR', 'USD'],
        locale: 'ar',
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
    },
    url: '/my-account',
}));

vi.mock('@inertiajs/react', () => ({
    Head: ({ title }: { title: string }) => <title>{title}</title>,
    usePage: () => mockPage,
}));

afterEach(cleanup);

it('uses the canonical Arabic customer account identity inside the storefront shell', () => {
    render(<AccountOverview />);

    expect(
        screen.getByRole('heading', { level: 1, name: 'حسابي' }),
    ).toBeVisible();
    expect(
        screen.getByRole('heading', { level: 2, name: 'نظرة عامة' }),
    ).toBeVisible();
    expect(
        document.documentElement.querySelector('[lang="ar"]'),
    ).toHaveAttribute('dir', 'rtl');
    expect(document.title).toBe('حسابي');
    expect(screen.queryByText('Dashboard')).not.toBeInTheDocument();
});
