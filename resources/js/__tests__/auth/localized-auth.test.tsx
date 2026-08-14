import {
    cleanup,
    fireEvent,
    render,
    screen,
    waitFor,
} from '@testing-library/react';
import type { ReactNode } from 'react';
import { afterEach, expect, it, vi } from 'vitest';

import AuthLayout from '@/layouts/auth-layout';
import ForgotPassword from '@/pages/auth/forgot-password';
import Login from '@/pages/auth/login';
import Register from '@/pages/auth/register';
import ResetPasswordPage from '@/pages/auth/reset-password';
import type { AuthUiTranslations } from '@/types/auth';

const page = vi.hoisted(() => ({ props: {} as Record<string, unknown> }));

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
    router: { visit: vi.fn() },
}));

vi.mock('@/layouts/store-layout', () => ({
    default: ({
        children,
        direction,
        locale,
    }: {
        children: ReactNode;
        direction: 'rtl' | 'ltr';
        locale: 'ar' | 'en';
    }) => (
        <div className="store-shell" dir={direction} lang={locale}>
            {children}
        </div>
    ),
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

const arabicUi = {
    brand: 'عرب التيميت',
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

const englishUi = {
    ...arabicUi,
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

afterEach(() => {
    cleanup();
    vi.restoreAllMocks();
    document.head.innerHTML = '';
});

class TestResizeObserver {
    observe() {}
    unobserve() {}
    disconnect() {}
}

vi.stubGlobal('ResizeObserver', TestResizeObserver);

it('renders the Arabic login handoff and sends a normalized WhatsApp code request', async () => {
    document.head.innerHTML =
        '<meta name="csrf-token" content="csrf-test-token">';
    const fetchMock = vi.spyOn(globalThis, 'fetch').mockResolvedValue(
        new Response(JSON.stringify({ data: { sent: true } }), {
            status: 200,
            headers: { 'Content-Type': 'application/json' },
        }),
    );
    page.props = {
        authPage: 'login',
        authRoutes: routes,
        authUi: arabicUi,
        direction: 'rtl',
        locale: 'ar',
    };

    render(
        <AuthLayout>
            <Login authRoutes={routes} authUi={arabicUi} canResetPassword />
        </AuthLayout>,
    );

    expect(
        screen.getByRole('heading', { name: 'تسجيل الدخول إلى حسابك' }),
    ).toBeVisible();
    expect(document.querySelector('form')).toHaveAttribute('action', '/login');
    expect(screen.getByPlaceholderText(arabicUi.fields.password)).toBeVisible();
    expect(
        screen.getByRole('link', { name: 'نسيت كلمة المرور؟' }),
    ).toHaveAttribute('href', '/forgot-password');
    expect(screen.getByRole('link', { name: 'أنشئ حسابًا' })).toHaveAttribute(
        'href',
        '/register',
    );
    expect(document.querySelector('.auth-shell')).toHaveAttribute('dir', 'rtl');
    expect(screen.getByRole('tab', { name: 'الهاتف' })).toBeVisible();
    expect(
        screen.getByRole('link', { name: 'المتابعة باستخدام Google' }),
    ).toHaveAttribute('href', '/auth/google/redirect');

    fireEvent.click(screen.getByRole('tab', { name: 'الهاتف' }));
    fireEvent.change(screen.getByLabelText('رمز الدولة'), {
        target: { value: '+20' },
    });
    fireEvent.change(screen.getByLabelText('رقم الهاتف'), {
        target: { value: '0100 123-4567' },
    });
    fireEvent.click(
        screen.getByRole('button', { name: arabicUi.login.phone_send_code }),
    );

    await waitFor(() => expect(fetchMock).toHaveBeenCalledOnce());
    expect(fetchMock.mock.calls[0]?.[0].toString()).toBe(
        'http://localhost:3000/auth/whatsapp/code',
    );
    expect(JSON.parse(String(fetchMock.mock.calls[0]?.[1]?.body))).toEqual({
        phone: '+201001234567',
    });
    expect(screen.getByLabelText(arabicUi.login.phone_code)).toBeVisible();
});

it('renders English register forgot and reset forms with the localized route contract', () => {
    const englishRoutes = Object.fromEntries(
        Object.entries(routes).map(([key, value]) => [
            key,
            value === '/' ? '/en' : `/en${value}`,
        ]),
    ) as typeof routes;
    page.props = {
        authPage: 'register',
        authRoutes: englishRoutes,
        authUi: englishUi,
        direction: 'ltr',
        locale: 'en',
    };

    const { rerender } = render(
        <AuthLayout>
            <Register
                authRoutes={englishRoutes}
                authUi={englishUi}
                passwordRules="minlength:8"
            />
        </AuthLayout>,
    );
    expect(document.querySelector('form')).toHaveAttribute(
        'action',
        '/en/register',
    );
    expect(
        screen.getByRole('button', { name: 'Create account' }),
    ).toBeVisible();

    page.props.authPage = 'forgot_password';
    rerender(
        <AuthLayout>
            <ForgotPassword authRoutes={englishRoutes} authUi={englishUi} />
        </AuthLayout>,
    );
    expect(document.querySelector('form')).toHaveAttribute(
        'action',
        '/en/forgot-password',
    );
    expect(
        screen.getByRole('button', { name: 'Send reset link' }),
    ).toBeVisible();

    page.props.authPage = 'reset_password';
    rerender(
        <AuthLayout>
            <ResetPasswordPage
                authRoutes={englishRoutes}
                authUi={englishUi}
                email="player@example.test"
                passwordRules="minlength:8"
                token="safe-token"
            />
        </AuthLayout>,
    );
    expect(document.querySelector('form')).toHaveAttribute(
        'action',
        '/en/reset-password',
    );
    expect(
        screen.getByRole('button', { name: 'Save new password' }),
    ).toBeVisible();
    expect(document.querySelector('.auth-shell')).toHaveAttribute('dir', 'ltr');
});
