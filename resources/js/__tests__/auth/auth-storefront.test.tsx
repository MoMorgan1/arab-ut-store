import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import type { ReactNode } from 'react';
import { afterEach, describe, expect, it, vi } from 'vitest';

import AuthLayout from '@/layouts/auth-layout';
import ConfirmPassword from '@/pages/auth/confirm-password';
import ForgotPassword from '@/pages/auth/forgot-password';
import Login from '@/pages/auth/login';
import Register from '@/pages/auth/register';
import ResetPassword from '@/pages/auth/reset-password';
import type { AuthUiTranslations } from '@/types/auth';

const page = vi.hoisted(() => ({
    props: {} as Record<string, unknown>,
    url: '/',
}));

class TestResizeObserver {
    observe() {}
    unobserve() {}
    disconnect() {}
}

vi.stubGlobal('ResizeObserver', TestResizeObserver);

vi.mock('@inertiajs/react', () => ({
    Form: ({
        action,
        children,
        className,
        method,
    }: {
        action: string;
        children: (state: object) => ReactNode;
        className?: string;
        method: string;
    }) => (
        <form action={action} className={className} method={method}>
            {children({ processing: false, errors: {} })}
        </form>
    ),
    Head: ({ title }: { title: string }) => <title>{title}</title>,
    Link: ({
        children,
        href,
        ...props
    }: {
        children: ReactNode;
        href: string;
    }) => (
        <a href={href} {...props}>
            {children}
        </a>
    ),
    usePage: () => page,
}));

const routes = {
    homeUrl: '/',
    loginUrl: '/login',
    loginStoreUrl: '/login',
    registerUrl: '/register',
    registerStoreUrl: '/register',
    forgotPasswordUrl: '/forgot-password',
    forgotPasswordStoreUrl: '/forgot-password',
    resetPasswordStoreUrl: '/reset-password',
    googleLoginUrl: '/auth/google/redirect',
    whatsappSendUrl: '/auth/whatsapp/code',
    whatsappVerifyUrl: '/auth/whatsapp/verify',
};

const storeShell = {
    homeUrl: '/',
    coinsUrl: '/#coins',
    cartUrl: '/cart',
    sbcUrl: '/sbc',
    futChampionsUrl: '/fut-champions',
    accountUrl: '/login',
    privacyUrl: '/privacy',
    returnsUrl: '/returns',
    warrantyUrl: '/warranty',
    eaBackupCodesUrl: '/ea-backup-codes',
    termsUrl: '/terms',
    whatsappUrl: 'https://wa.me/966537998099',
    email: 'support@example.test',
    socials: {
        x: 'https://x.com/fut_fi',
        instagram: 'https://instagram.com/arabutcoins',
    },
    payments: [],
};

const storeUi = {
    brand: 'عرب التيميت',
    language: 'English',
    currency_selector: 'اختر عملة العرض',
    home_title: 'الرئيسية',
    skip_to_content: 'انتقل إلى المحتوى',
    store_tools: 'أدوات المتجر',
    header: {
        primary_navigation: 'التنقل الرئيسي',
        preferences: 'اللغة والعملة',
        home: 'الرئيسية',
        coins: 'كوينز',
        sbc: 'SBC',
        fut_champions: 'فوت تشامبيونز',
        most_requested: 'الأكثر طلبًا',
        whatsapp: 'تواصل معنا',
        cart: 'السلة',
        account: 'الحساب',
    },
    preferences: {
        exchange_rate_attribution: 'Rates By Exchange Rate API',
    },
    footer: {
        description: 'متجر عرب التيميت لخدمات FC 27.',
        important_links: 'روابط تهمك',
        privacy: 'سياسة الخصوصية',
        returns: 'سياسة الاسترجاع',
        warranty: 'سياسة الضمان',
        ea_backup_codes: 'أكواد EA الاحتياطية',
        terms: 'شروط الخدمة',
        customer_service: 'خدمة العملاء',
        whatsapp: 'واتساب',
        payment_methods: 'طرق الدفع المقبولة',
        copyright: '© :year عرب التيميت. جميع الحقوق محفوظة.',
        ea_disclaimer: 'Arab UT is not affiliated with EA Sports.',
    },
};

const arabicAuthUi = {
    brand: 'عرب التيميت',
    fields: {
        first_name: 'الاسم الأول',
        last_name: 'اسم العائلة',
        email: 'البريد الإلكتروني',
        password: 'كلمة المرور',
        password_confirmation: 'تأكيد كلمة المرور',
        remember: 'تذكرني',
    },
    password_visibility: {
        show: 'إظهار كلمة المرور',
        hide: 'إخفاء كلمة المرور',
    },
    benefits: {
        eyebrow: 'حسابك مع عرب التيميت',
        title: 'كمّل طلبك من نفس المكان',
        description:
            'تسجيل الدخول يربط حسابك بسلتك الحالية بدون ما تبدأ من جديد.',
        items: [
            'سلتك تكمل معك بعد تسجيل الدخول',
            'بيانات EA مشفّرة داخل السلة المؤقتة',
            'غيّر اللغة والعملة من نفس المتجر',
        ],
    },
    login: {
        head_title: 'تسجيل الدخول',
        title: 'تسجيل الدخول إلى حسابك',
        description: 'أدخل بريدك الإلكتروني وكلمة المرور للمتابعة.',
        submit: 'تسجيل الدخول',
        forgot_password: 'نسيت كلمة المرور؟',
        registration_prompt: 'ما عندك حساب؟',
        registration_link: 'أنشئ حسابًا',
        email_tab: 'البريد الإلكتروني',
        phone_tab: 'الهاتف',
        country_code: 'رمز الدولة',
        phone_number: 'رقم الهاتف',
        phone_existing_only:
            'تسجيل الدخول بواتساب يعمل فقط لرقم مرتبط مسبقًا بحساب نشط.',
        phone_send_code: 'أرسل كود واتساب',
        phone_code: 'كود واتساب المكوّن من 6 أرقام',
        phone_verify: 'تحقق وسجّل الدخول',
        phone_code_sent:
            'إذا كان الرقم مرتبطًا بحساب، أرسلنا له كودًا على واتساب.',
        phone_code_invalid: 'الكود غير صحيح أو انتهت صلاحيته.',
        phone_invalid: 'أدخل رقم هاتف صحيحًا مع رمز الدولة.',
        phone_unavailable:
            'تعذر إرسال كود واتساب الآن. حاول مرة أخرى بعد قليل.',
        phone_change: 'تغيير الرقم',
        google: 'المتابعة باستخدام Google',
        google_error: 'تعذر تسجيل الدخول باستخدام Google. حاول مرة أخرى.',
        or: 'أو',
    },
    register: {
        head_title: 'إنشاء حساب',
        title: 'إنشاء حساب',
        description: 'أدخل بياناتك للبدء مع عرب التيميت.',
        submit: 'إنشاء الحساب',
        login_prompt: 'عندك حساب؟',
        login_link: 'سجّل الدخول',
        password_symbol_error: 'أضف رمزًا واحدًا على الأقل، مثل ! أو @ أو #.',
    },
    forgot_password: {
        head_title: 'نسيت كلمة المرور',
        title: 'نسيت كلمة المرور؟',
        description: 'أدخل بريدك وسنرسل لك رابطًا آمنًا لإعادة التعيين.',
        submit: 'إرسال رابط إعادة التعيين',
        return_prompt: 'تذكرت كلمة المرور؟',
        return_link: 'ارجع لتسجيل الدخول',
    },
    reset_password: {
        head_title: 'تعيين كلمة مرور جديدة',
        title: 'تعيين كلمة مرور جديدة',
        description: 'اختر كلمة مرور جديدة لحسابك.',
        submit: 'حفظ كلمة المرور الجديدة',
    },
    confirm_password: {
        head_title: 'تأكيد كلمة المرور',
        title: 'أكّد كلمة المرور',
        description: 'هذه منطقة آمنة. أكّد كلمة المرور للمتابعة.',
        submit: 'تأكيد كلمة المرور',
    },
} satisfies AuthUiTranslations;

const englishAuthUi = {
    ...arabicAuthUi,
    brand: 'Arab UT',
    fields: {
        first_name: 'First name',
        last_name: 'Last name',
        email: 'Email address',
        password: 'Password',
        password_confirmation: 'Confirm password',
        remember: 'Remember me',
    },
    password_visibility: { show: 'Show password', hide: 'Hide password' },
    benefits: {
        eyebrow: 'Your Arab UT account',
        title: 'Continue your order in one place',
        description: 'Sign in to connect your account to your current cart.',
        items: [
            'Your cart continues after you sign in',
            'EA credentials stay encrypted in the temporary cart',
            'Change language and currency in the same store',
        ],
    },
    login: {
        head_title: 'Log in',
        title: 'Log in to your account',
        description: 'Enter your email and password to continue.',
        submit: 'Log in',
        forgot_password: 'Forgot your password?',
        registration_prompt: "Don't have an account?",
        registration_link: 'Create an account',
        email_tab: 'Email',
        phone_tab: 'Phone',
        country_code: 'Country code',
        phone_number: 'Phone number',
        phone_existing_only:
            'WhatsApp sign-in works only for a phone number already linked to an active account.',
        phone_send_code: 'Send WhatsApp code',
        phone_code: '6-digit WhatsApp code',
        phone_verify: 'Verify and log in',
        phone_code_sent: 'If linked, a WhatsApp code was sent.',
        phone_code_invalid: 'The code is invalid or expired.',
        phone_invalid: 'Enter a valid phone number.',
        phone_unavailable: 'Could not send a WhatsApp code.',
        phone_change: 'Change number',
        google: 'Continue with Google',
        google_error:
            'Google sign-in could not be completed. Please try again.',
        or: 'or',
    },
    register: {
        head_title: 'Create account',
        title: 'Create an account',
        description: 'Enter your details to get started with Arab UT.',
        submit: 'Create account',
        login_prompt: 'Already have an account?',
        login_link: 'Log in',
        password_symbol_error: 'Add at least one symbol, such as !, @, or #.',
    },
    forgot_password: {
        head_title: 'Forgot password',
        title: 'Forgot your password?',
        description: 'Enter your email and we will send a secure reset link.',
        submit: 'Send reset link',
        return_prompt: 'Remembered your password?',
        return_link: 'Back to log in',
    },
    reset_password: {
        head_title: 'Set a new password',
        title: 'Set a new password',
        description: 'Choose a new password for your account.',
        submit: 'Save new password',
    },
    confirm_password: {
        head_title: 'Confirm password',
        title: 'Confirm your password',
        description:
            'This is a secure area. Confirm your password to continue.',
        submit: 'Confirm password',
    },
} satisfies AuthUiTranslations;

function setPage(
    authPage: 'login' | 'register' | 'forgot_password' | 'reset_password',
    locale: 'ar' | 'en' = 'ar',
) {
    const english = locale === 'en';
    page.url = english
        ? `/en/${authPage.replace('_', '-')}`
        : `/${authPage.replace('_', '-')}`;
    page.props = {
        authPage,
        authRoutes: english
            ? Object.fromEntries(
                  Object.entries(routes).map(([key, value]) => [
                      key,
                      value === '/' ? '/en' : `/en${value}`,
                  ]),
              )
            : routes,
        authUi: english ? englishAuthUi : arabicAuthUi,
        cartCount: 2,
        direction: english ? 'ltr' : 'rtl',
        displayCurrencies: ['SAR', 'USD', 'EUR'],
        displayCurrency: 'SAR',
        locale,
        storeShell: english
            ? Object.fromEntries(
                  Object.entries(storeShell).map(([key, value]) => [
                      key,
                      typeof value === 'string' && value.startsWith('/')
                          ? value === '/'
                              ? '/en'
                              : `/en${value}`
                          : value,
                  ]),
              )
            : storeShell,
        ui: english
            ? {
                  ...storeUi,
                  brand: 'Arab UT',
                  language: 'العربية',
                  skip_to_content: 'Skip to content',
                  header: {
                      ...storeUi.header,
                      primary_navigation: 'Primary navigation',
                      preferences: 'Language and currency',
                      home: 'Home',
                      coins: 'Coins',
                      fut_champions: 'FUT Champions',
                      whatsapp: 'Contact us',
                      cart: 'Cart',
                      account: 'Account',
                  },
              }
            : storeUi,
    };
}

function expectBenefitsBeforeForm() {
    const formCard = document.querySelector('.auth-shell__form-card');
    const benefits = document.querySelector('.auth-shell__benefits');

    expect(formCard).not.toBeNull();
    expect(benefits).not.toBeNull();
    expect(benefits?.compareDocumentPosition(formCard as Node) ?? 0).toBe(
        Node.DOCUMENT_POSITION_FOLLOWING,
    );
}

afterEach(cleanup);

describe('storefront authentication shell', () => {
    it('renders password confirmation inside the localized focused storefront shell', () => {
        setPage('login');
        page.props = {
            ...page.props,
            authPage: 'confirm_password',
            authUi: arabicAuthUi,
        };

        render(
            <AuthLayout>
                <ConfirmPassword authUi={arabicAuthUi} />
            </AuthLayout>,
        );

        expect(
            screen.getByRole('heading', { name: 'أكّد كلمة المرور' }),
        ).toBeVisible();
        expect(
            screen.getByRole('button', { name: 'تأكيد كلمة المرور' }),
        ).toBeVisible();
        expect(document.querySelector('.auth-shell__benefits')).toBeNull();
    });

    it('keeps Arabic login inside one complete storefront shell with truthful benefits', () => {
        setPage('login');

        render(
            <AuthLayout>
                <Login
                    authRoutes={routes}
                    authUi={arabicAuthUi}
                    canResetPassword
                />
            </AuthLayout>,
        );

        expect(screen.getAllByRole('banner')).toHaveLength(1);
        expect(
            screen.getAllByRole('navigation', { name: 'التنقل الرئيسي' }),
        ).toHaveLength(1);
        expect(screen.getAllByRole('contentinfo')).toHaveLength(1);
        expect(document.querySelector('.store-shell')).toHaveAttribute(
            'dir',
            'rtl',
        );
        expect(screen.getByRole('main')).toHaveAttribute('id', 'store-content');
        expect(
            screen.getByRole('heading', { name: 'تسجيل الدخول إلى حسابك' }),
        ).toHaveClass('auth-shell__title');
        expectBenefitsBeforeForm();

        for (const benefit of arabicAuthUi.benefits.items) {
            expect(screen.getByText(benefit)).toBeVisible();
        }

        expect(screen.getByRole('link', { name: 'الحساب' })).toHaveAttribute(
            'href',
            '/login',
        );
        expect(screen.getByRole('link', { name: /Google/i })).toHaveAttribute(
            'href',
            '/auth/google/redirect',
        );
        expect(screen.queryByText(/checkout/i)).not.toBeInTheDocument();
        expect(
            screen.getByRole('button', { name: 'إظهار كلمة المرور' }),
        ).toHaveProperty('tabIndex', 0);
        expect(
            screen.getByRole('button', { name: 'إظهار كلمة المرور' }),
        ).toHaveClass('right-0');
        expect(screen.getByLabelText('كلمة المرور')).toHaveClass('pr-11');
        expect(
            screen.getByText(arabicAuthUi.fields.remember).closest('label'),
        ).toHaveClass('min-h-11', 'flex-1');
    });

    it('renders English registration form first with the localized benefits', () => {
        setPage('register', 'en');
        const englishRoutes = page.props.authRoutes as typeof routes;

        render(
            <AuthLayout>
                <Register
                    authRoutes={englishRoutes}
                    authUi={englishAuthUi}
                    passwordRules="minlength:8"
                />
            </AuthLayout>,
        );

        expect(document.querySelector('.store-shell')).toHaveAttribute(
            'dir',
            'ltr',
        );
        expectBenefitsBeforeForm();

        for (const benefit of englishAuthUi.benefits.items) {
            expect(screen.getByText(benefit)).toBeVisible();
        }

        expect(document.querySelector('form')).toHaveAttribute(
            'action',
            '/en/register',
        );
        expect(
            screen.getByRole('button', { name: 'Create account' }),
        ).toHaveClass('auth-form__submit');
    });

    it('shows the Arabic symbol requirement while the password is being typed', () => {
        setPage('register');

        render(
            <AuthLayout>
                <Register
                    authRoutes={routes}
                    authUi={arabicAuthUi}
                    passwordRules="minlength:12; required: special;"
                />
            </AuthLayout>,
        );

        const password = screen.getByLabelText(arabicAuthUi.fields.password);

        expect(
            screen.queryByText(arabicAuthUi.register.password_symbol_error),
        ).not.toBeInTheDocument();

        fireEvent.change(password, { target: { value: 'StrongPassword12' } });

        expect(
            screen.getByText(arabicAuthUi.register.password_symbol_error),
        ).toBeVisible();
        expect(password).toHaveAttribute('aria-invalid', 'true');
        expect(password).toHaveAttribute(
            'aria-describedby',
            'password-symbol-error',
        );

        fireEvent.change(password, {
            target: { value: 'StrongPassword12!' },
        });

        expect(
            screen.queryByText(arabicAuthUi.register.password_symbol_error),
        ).not.toBeInTheDocument();
        expect(password).toHaveAttribute('aria-invalid', 'false');
    });

    it('explains that WhatsApp login is only for an existing linked account', () => {
        setPage('login', 'en');
        const englishRoutes = page.props.authRoutes as typeof routes;

        render(
            <AuthLayout>
                <Login
                    authRoutes={englishRoutes}
                    authUi={englishAuthUi}
                    canResetPassword
                />
            </AuthLayout>,
        );

        fireEvent.click(screen.getByRole('tab', { name: 'Phone' }));
        expect(
            screen.getByText(
                'WhatsApp sign-in works only for a phone number already linked to an active account.',
            ),
        ).toBeVisible();
    });

    it('keeps forgot and reset password focused without a benefits panel', () => {
        setPage('forgot_password', 'en');
        const englishRoutes = page.props.authRoutes as typeof routes;
        const { container, rerender } = render(
            <AuthLayout>
                <ForgotPassword
                    authRoutes={englishRoutes}
                    authUi={englishAuthUi}
                />
            </AuthLayout>,
        );

        expect(container.querySelector('.auth-shell__grid')).toHaveClass(
            'auth-shell__grid--focused',
        );
        expect(
            container.querySelector('.auth-shell__benefits'),
        ).not.toBeInTheDocument();

        setPage('reset_password', 'en');
        rerender(
            <AuthLayout>
                <ResetPassword
                    authRoutes={englishRoutes}
                    authUi={englishAuthUi}
                    email="player@example.test"
                    passwordRules="minlength:8"
                    token="safe-reset-token"
                />
            </AuthLayout>,
        );

        expect(
            container.querySelector('.auth-shell__benefits'),
        ).not.toBeInTheDocument();
        expect(screen.getByDisplayValue('player@example.test')).toHaveAttribute(
            'readonly',
        );
        expect(document.body.textContent).not.toContain('safe-reset-token');
    });
});
