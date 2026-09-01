import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, expect, it, vi } from 'vitest';

import AccountOverview from '@/pages/account/overview';

const routerPost = vi.hoisted(() => vi.fn());

const mockPage = vi.hoisted(() => ({
    props: {
        accountUi: {
            page_title: 'حسابي',
            eyebrow: 'حساب عرب التيميت',
            greeting: 'مرحبًا، :name',
            introduction: 'تابع طلباتك ورصيدك وبيانات حسابك من مكان واحد.',
            email_alert: {
                title: 'وثّق بريدك الإلكتروني',
                desc: 'التوثيق يحمي حسابك ويتيح استرجاع كلمة المرور.',
                action: 'إرسال رابط التوثيق',
                sent: 'أرسلنا رابط التوثيق إلى بريدك. تفقد الوارد.',
                resend_in: 'إعادة الإرسال بعد :seconds ث',
            },
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
                open_orders_metric: 'قيد التنفيذ',
                completed_orders_metric: 'مكتملة',
                wallet_metric: 'رصيد المحفظة',
                active_order: 'طلب يحتاج متابعتك',
                recent_orders: 'أحدث الطلبات',
                loyalty: 'تقدم الولاء',
                empty_title: 'كل شيء جاهز لأول طلب لك',
                empty_description: 'لم تنشئ أي طلب بعد.',
                browse_services: 'تصفح الخدمات',
                loyalty_remaining: 'تبقى :amount للوصول إلى فئة :tier.',
                loyalty_complete: 'وصلت إلى أعلى فئة ولاء متاحة.',
                attention_description:
                    'نحتاج بيانات دخول الحساب لإكمال التنفيذ.',
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
            statuses: {},
            accessibility: { order_status: 'حالة الطلب: :status' },
            wallet: {},
        },
        accountIdentity: { name: 'محمد لاعب', greeting: 'مرحبًا، محمد لاعب' },
        accountNavigation: [
            { key: 'overview', label: 'نظرة عامة', url: '/my-account' },
        ],
        activeOrder: null,
        recentOrders: [],
        loyalty: null,
        summary: {
            orderCount: 0,
            openOrderCount: 0,
            completedOrderCount: 0,
            walletBalance: { amountMinor: '0', currency: 'SAR' },
        },
        cartCount: 0,
        direction: 'rtl',
        displayCurrency: 'SAR',
        displayCurrencies: ['SAR', 'USD'],
        locale: 'ar',
        logoutUrl: '/logout',
        status: null as string | null,
        auth: {
            user: {
                id: 1,
                first_name: 'محمد',
                last_name: 'لاعب',
                name: 'محمد لاعب',
                email: 'owner@example.test' as string | null,
                email_verified_at: null as string | null,
            },
        },
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
            header: { account: 'حسابي' },
            footer: { copyright: 'الحقوق محفوظة.' },
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
        post: routerPost,
    },
    usePage: () => mockPage,
}));

afterEach(() => {
    cleanup();
    routerPost.mockClear();
    mockPage.props.status = null;
    mockPage.props.auth.user.email = 'owner@example.test';
    mockPage.props.auth.user.email_verified_at = null;
    mockPage.props.locale = 'ar';
});

it('shows the banner for a user with an unverified email and posts to the localized resend endpoint', () => {
    render(<AccountOverview />);

    const banner = screen.getByRole('complementary', {
        name: 'وثّق بريدك الإلكتروني',
    });
    expect(banner).toBeVisible();

    const send = screen.getByRole('button', { name: 'إرسال رابط التوثيق' });
    expect(send).toBeVisible();

    fireEvent.click(send);

    expect(routerPost).toHaveBeenCalledTimes(1);
    expect(routerPost).toHaveBeenCalledWith(
        '/verify-email/send',
        {},
        expect.objectContaining({ preserveScroll: true }),
    );
});

it('shows the sent confirmation once the verification link was dispatched', () => {
    mockPage.props.status = 'verification-link-sent';

    render(<AccountOverview />);

    expect(
        screen.getByText('أرسلنا رابط التوثيق إلى بريدك. تفقد الوارد.'),
    ).toBeVisible();
    expect(screen.getByRole('status')).toBeVisible();
});

it('never shows the banner for a customer without an email address', () => {
    mockPage.props.auth.user.email = null;

    render(<AccountOverview />);

    expect(
        screen.queryByRole('button', { name: 'إرسال رابط التوثيق' }),
    ).not.toBeInTheDocument();
    expect(screen.queryByText('وثّق بريدك الإلكتروني')).not.toBeInTheDocument();
});

it('never shows the banner once the email is verified', () => {
    mockPage.props.auth.user.email_verified_at = '2026-08-30T09:00:00.000000Z';

    render(<AccountOverview />);

    expect(
        screen.queryByRole('button', { name: 'إرسال رابط التوثيق' }),
    ).not.toBeInTheDocument();
});

it('points the english account at the english resend endpoint', () => {
    mockPage.props.locale = 'en';
    mockPage.props.direction = 'ltr';
    mockPage.props.accountUi = {
        ...mockPage.props.accountUi,
        email_alert: {
            title: 'Verify your email address',
            desc: 'Verification protects your account.',
            action: 'Send verification link',
            sent: 'We sent a verification link to your email.',
            resend_in: 'Resend in :seconds s',
        },
    };

    render(<AccountOverview />);

    fireEvent.click(
        screen.getByRole('button', { name: 'Send verification link' }),
    );

    expect(routerPost).toHaveBeenCalledWith(
        '/en/verify-email/send',
        {},
        expect.objectContaining({ preserveScroll: true }),
    );
});
