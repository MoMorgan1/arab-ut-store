import { cleanup, render, screen } from '@testing-library/react';
import type { ReactNode } from 'react';
import { afterEach, expect, it, vi } from 'vitest';

import AuthLayout from '@/layouts/auth-layout';
import ForgotPassword from '@/pages/auth/forgot-password';
import Login from '@/pages/auth/login';
import Register from '@/pages/auth/register';
import ResetPasswordPage from '@/pages/auth/reset-password';

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
};

const arabicUi = {
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
    login: {
        head_title: 'تسجيل الدخول',
        title: 'تسجيل الدخول إلى حسابك',
        description: 'أدخل بريدك الإلكتروني وكلمة المرور للمتابعة.',
        submit: 'تسجيل الدخول',
        forgot_password: 'نسيت كلمة المرور؟',
        registration_prompt: 'ما عندك حساب؟',
        registration_link: 'أنشئ حسابًا',
    },
    register: {
        head_title: 'إنشاء حساب',
        title: 'إنشاء حساب',
        description: 'أدخل بياناتك للبدء مع عرب التيميت.',
        submit: 'إنشاء الحساب',
        login_prompt: 'عندك حساب؟',
        login_link: 'سجّل الدخول',
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
};

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
    register: {
        head_title: 'Create account',
        title: 'Create an account',
        description: 'Enter your details to get started with Arab UT.',
        submit: 'Create account',
        login_prompt: 'Already have an account?',
        login_link: 'Log in',
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
};

afterEach(cleanup);

class TestResizeObserver {
    observe() {}
    unobserve() {}
    disconnect() {}
}

vi.stubGlobal('ResizeObserver', TestResizeObserver);

it('renders the Arabic login handoff in RTL with localized links and form action', () => {
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
