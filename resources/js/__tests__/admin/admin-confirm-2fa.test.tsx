import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { englishAdminUi } from '@/__tests__/admin/admin-test-fixtures';
import AdminConfirmTwoFactor from '@/pages/admin/confirm-2fa';
import type { AdminTranslations } from '@/types/admin';

const mockPost = vi.fn();
const mockReset = vi.fn();
const mockClearErrors = vi.fn();
const mockSetData = vi.fn();
const mockRouterPost = vi.fn();

vi.mock('@inertiajs/react', () => ({
    Head: ({ title }: { title: string }) => <title>{title}</title>,
    router: {
        post: (...args: unknown[]) => mockRouterPost(...args),
        flushAll: vi.fn(),
    },
    useForm: (initialData: { code: string; recovery_code: string }) => ({
        data: initialData,
        setData: mockSetData,
        post: mockPost,
        processing: false,
        errors: {},
        reset: mockReset,
        clearErrors: mockClearErrors,
    }),
}));

const mockAdminUi: AdminTranslations = {
    ...englishAdminUi,
    confirm2fa: {
        headTitle: 'Admin Two-Factor Verification',
        title: 'Confirm Authenticator Code',
        description:
            'Enter the 6-digit verification code from your authenticator app.',
        code: 'Authenticator code',
        recoveryCode: 'Recovery code',
        useRecoveryCode: 'Use a recovery code',
        useAuthenticatorCode: 'Use an authenticator code',
        invalidCode: 'The code is invalid or has expired.',
        invalidRecoveryCode:
            'The recovery code is invalid or has already been used.',
        submit: 'Verify and continue',
        submitting: 'Verifying…',
        logout: 'Log out',
    },
};

describe('AdminConfirmTwoFactor', () => {
    beforeEach(() => {
        vi.clearAllMocks();
    });

    afterEach(() => {
        cleanup();
    });

    it('renders the TOTP confirmation form with authenticator code by default', () => {
        render(
            <AdminConfirmTwoFactor
                adminUi={mockAdminUi}
                confirmUrl="/admin/confirm-2fa"
                direction="ltr"
                locale="en"
                logoutUrl="/logout"
            />,
        );

        expect(
            screen.getByText('Confirm Authenticator Code'),
        ).toBeInTheDocument();
        expect(
            screen.getByText(
                'Enter the 6-digit verification code from your authenticator app.',
            ),
        ).toBeInTheDocument();
        expect(screen.getByLabelText('Authenticator code')).toBeInTheDocument();
        expect(
            screen.getByRole('button', { name: 'Use a recovery code' }),
        ).toBeInTheDocument();
        expect(
            screen.getByRole('button', { name: 'Verify and continue' }),
        ).toBeInTheDocument();
        expect(
            screen.getByRole('button', { name: 'Log out' }),
        ).toBeInTheDocument();
    });

    it('toggles between authenticator code and recovery code mode', () => {
        render(
            <AdminConfirmTwoFactor
                adminUi={mockAdminUi}
                confirmUrl="/admin/confirm-2fa"
                direction="ltr"
                locale="en"
                logoutUrl="/logout"
            />,
        );

        const toggleButton = screen.getByRole('button', {
            name: 'Use a recovery code',
        });
        fireEvent.click(toggleButton);

        expect(screen.getByLabelText('Recovery code')).toBeInTheDocument();
        expect(
            screen.getByRole('button', { name: 'Use an authenticator code' }),
        ).toBeInTheDocument();
        expect(mockReset).toHaveBeenCalled();
        expect(mockClearErrors).toHaveBeenCalled();

        // Toggle back
        fireEvent.click(
            screen.getByRole('button', { name: 'Use an authenticator code' }),
        );
        expect(screen.getByLabelText('Authenticator code')).toBeInTheDocument();
    });

    it('submits the form to confirmUrl', () => {
        render(
            <AdminConfirmTwoFactor
                adminUi={mockAdminUi}
                confirmUrl="/admin/confirm-2fa"
                direction="ltr"
                locale="en"
                logoutUrl="/logout"
            />,
        );

        const submitButton = screen.getByRole('button', {
            name: 'Verify and continue',
        });
        fireEvent.submit(submitButton.closest('form')!);

        expect(mockPost).toHaveBeenCalledWith(
            '/admin/confirm-2fa',
            expect.objectContaining({
                preserveScroll: true,
            }),
        );
    });

    it('triggers logout on clicking the logout button', () => {
        render(
            <AdminConfirmTwoFactor
                adminUi={mockAdminUi}
                confirmUrl="/admin/confirm-2fa"
                direction="ltr"
                locale="en"
                logoutUrl="/logout"
            />,
        );

        const logoutButton = screen.getByRole('button', { name: 'Log out' });
        fireEvent.click(logoutButton);

        expect(mockRouterPost).toHaveBeenCalledWith('/logout');
    });

    it('renders in Arabic RTL layout correctly', () => {
        const arabicAdminUi: AdminTranslations = {
            ...mockAdminUi,
            confirm2fa: {
                headTitle: 'التحقق بخطوتين للإدارة',
                title: 'تأكيد رمز المصادقة',
                description: 'أدخل رمز التحقق المكون من 6 أرقام.',
                code: 'رمز تطبيق المصادقة',
                recoveryCode: 'رمز الاسترداد',
                useRecoveryCode: 'استخدم رمز استرداد',
                useAuthenticatorCode: 'استخدم رمز تطبيق المصادقة',
                invalidCode: 'الرمز غير صحيح أو انتهت صلاحيته.',
                invalidRecoveryCode: 'رمز الاسترداد غير صحيح أو تم استخدامه.',
                submit: 'تأكيد والمتابعة',
                submitting: 'جاري التحقق…',
                logout: 'تسجيل الخروج',
            },
        };

        const { container } = render(
            <AdminConfirmTwoFactor
                adminUi={arabicAdminUi}
                confirmUrl="/admin/confirm-2fa"
                direction="rtl"
                locale="ar"
                logoutUrl="/logout"
            />,
        );

        expect(screen.getByText('تأكيد رمز المصادقة')).toBeInTheDocument();
        expect(screen.getByLabelText('رمز تطبيق المصادقة')).toBeInTheDocument();
        expect(container.querySelector('[dir="rtl"]')).toBeInTheDocument();
    });
});
