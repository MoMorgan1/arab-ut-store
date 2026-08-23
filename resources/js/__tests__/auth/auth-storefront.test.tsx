import {
    cleanup,
    fireEvent,
    render,
    screen,
    within,
} from '@testing-library/react';
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
        tab_email: 'البريد وكلمة المرور',
        tab_email_short: 'البريد',
        phone_tab: 'واتساب',
        country_code: 'رمز الدولة',
        phone_number: 'رقم واتساب',
        phone_account_hint:
            'سنرسل كودًا من 6 أرقام على واتساب. إن لم يكن لديك حساب سننشئ واحدًا بهذا الرقم.',
        phone_send_code: 'إرسال الكود',
        phone_code: 'كود واتساب المكوّن من 6 أرقام',
        phone_verify: 'تحقق وتابع',
        phone_code_sent: 'أرسلنا لك كودًا على واتساب.',
        phone_code_sent_to: 'أرسلنا 6 أرقام على واتساب إلى :number',
        phone_code_invalid: 'الكود غير صحيح أو انتهت صلاحيته.',
        phone_invalid: 'أدخل رقم هاتف صحيحًا مع رمز الدولة.',
        phone_unavailable:
            'تعذر إرسال كود واتساب الآن. حاول مرة أخرى بعد قليل.',
        phone_change: 'تغيير الرقم',
        phone_resend_in: 'إعادة الإرسال بعد :seconds ث',
        phone_resend: 'إعادة إرسال الكود',
        phone_help: 'لم يصلك الكود؟ تأكد أن واتساب مفعّل على هذا الرقم، أو',
        phone_help_support: 'تواصل مع الدعم',
        google: 'المتابعة بحساب Google',
        google_error: 'تعذر تسجيل الدخول باستخدام Google. حاول مرة أخرى.',
        or: 'أو',
        terms_prefix: 'بالمتابعة أنت توافق على',
        terms_link: 'الشروط والأحكام',
        terms_and: 'و',
        privacy_link: 'سياسة الخصوصية',
    },
    register: {
        head_title: 'إنشاء حساب',
        title: 'إنشاء حساب',
        description: 'أدخل بياناتك للبدء مع عرب التيميت.',
        submit: 'إنشاء الحساب',
        login_prompt: 'عندك حساب؟',
        login_link: 'سجّل الدخول',
        password_requirements: {
            title: 'يجب أن تحتوي كلمة المرور على:',
            minimum: '12 حرفًا على الأقل',
            mixed_case: 'حرف إنجليزي كبير وحرف صغير',
            number: 'رقم واحد على الأقل',
            symbol: 'رمز واحد على الأقل، مثل ! أو @ أو #',
        },
        phone_unavailable:
            'هذا الرقم مرتبط بحساب آخر. سجّل الدخول بالرقم بدلًا من إنشاء حساب جديد.',
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
    two_factor_challenge: {
        head_title: 'التحقق بخطوتين',
        title: 'أكّد هويتك',
        description: 'أدخل الرمز من تطبيق المصادقة للمتابعة.',
        code: 'رمز تطبيق المصادقة',
        recovery_code: 'رمز الاسترداد',
        use_recovery_code: 'استخدم رمز استرداد',
        use_authenticator_code: 'استخدم رمز تطبيق المصادقة',
        invalid_code: 'الرمز غير صحيح أو انتهت صلاحيته.',
        invalid_recovery_code: 'رمز الاسترداد غير صحيح أو تم استخدامه.',
        submit: 'تأكيد الدخول',
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
        tab_email: 'Email and password',
        tab_email_short: 'Email',
        phone_tab: 'WhatsApp',
        country_code: 'Country code',
        phone_number: 'WhatsApp number',
        phone_account_hint:
            'We will send a 6-digit code on WhatsApp. If you do not have an account, we will create one with this number.',
        phone_send_code: 'Send code',
        phone_code: '6-digit WhatsApp code',
        phone_verify: 'Verify and continue',
        phone_code_sent: 'We sent you a WhatsApp code.',
        phone_code_sent_to: 'We sent 6 digits on WhatsApp to :number',
        phone_code_invalid: 'The code is invalid or expired.',
        phone_invalid: 'Enter a valid phone number.',
        phone_unavailable: 'Could not send a WhatsApp code.',
        phone_change: 'Change number',
        phone_resend_in: 'Resend in :seconds s',
        phone_resend: 'Resend code',
        phone_help:
            "Didn't receive the code? Make sure WhatsApp is active on this number, or",
        phone_help_support: 'contact support',
        google: 'Continue with Google',
        google_error:
            'Google sign-in could not be completed. Please try again.',
        or: 'or',
        terms_prefix: 'By continuing, you agree to our',
        terms_link: 'Terms and Conditions',
        terms_and: 'and',
        privacy_link: 'Privacy Policy',
    },
    register: {
        head_title: 'Create account',
        title: 'Create an account',
        description: 'Enter your details to get started with Arab UT.',
        submit: 'Create account',
        login_prompt: 'Already have an account?',
        login_link: 'Log in',
        password_requirements: {
            title: 'Your password must include:',
            minimum: 'At least 12 characters',
            mixed_case: 'One uppercase and one lowercase letter',
            number: 'At least one number',
            symbol: 'At least one symbol, such as !, @, or #',
        },
        phone_unavailable:
            'This number is linked to another account. Sign in with the number instead.',
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
        ).toHaveClass('min-h-10', 'flex-1');
        expect(screen.getByRole('tab', { name: /البريد/ })).toHaveAttribute(
            'aria-selected',
            'true',
        );
        expect(screen.getByRole('tab', { name: 'واتساب' })).toHaveAttribute(
            'aria-selected',
            'false',
        );
        const terms = within(
            document.querySelector('.auth-terms') as HTMLElement,
        );

        expect(
            terms.getByRole('link', { name: 'الشروط والأحكام' }),
        ).toHaveAttribute('href', '/terms');
        expect(
            terms.getByRole('link', { name: 'سياسة الخصوصية' }),
        ).toHaveAttribute('href', '/privacy');
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

    it('shows every Arabic password requirement and marks each one as it is met', () => {
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

        const requirements = arabicAuthUi.register.password_requirements;
        const minimum = screen.getByText(requirements.minimum);
        const mixedCase = screen.getByText(requirements.mixed_case);
        const number = screen.getByText(requirements.number);
        const symbol = screen.getByText(requirements.symbol);

        expect(minimum).toHaveAttribute('data-met', 'false');
        expect(mixedCase).toHaveAttribute('data-met', 'false');
        expect(number).toHaveAttribute('data-met', 'false');
        expect(symbol).toHaveAttribute('data-met', 'false');

        fireEvent.change(password, { target: { value: 'StrongPassword' } });
        expect(minimum).toHaveAttribute('data-met', 'true');
        expect(mixedCase).toHaveAttribute('data-met', 'true');
        expect(number).toHaveAttribute('data-met', 'false');
        expect(symbol).toHaveAttribute('data-met', 'false');

        fireEvent.change(password, {
            target: { value: 'StrongPassword12' },
        });
        expect(number).toHaveAttribute('data-met', 'true');
        expect(symbol).toHaveAttribute('data-met', 'false');

        fireEvent.change(password, { target: { value: 'StrongPassword12!' } });
        expect(symbol).toHaveAttribute('data-met', 'true');
    });

    it('explains that WhatsApp verification supports existing and new accounts', () => {
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

        expect(screen.getByRole('tab', { name: /Email/ })).toHaveAttribute(
            'aria-selected',
            'true',
        );
        expect(
            screen.getByRole('link', { name: 'Terms and Conditions' }),
        ).toHaveAttribute('href', '/en/terms');
        expect(
            screen.getByRole('link', { name: 'Privacy Policy' }),
        ).toHaveAttribute('href', '/en/privacy');

        fireEvent.click(screen.getByRole('tab', { name: 'WhatsApp' }));
        expect(
            screen.getByText(
                'We will send a 6-digit code on WhatsApp. If you do not have an account, we will create one with this number.',
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
