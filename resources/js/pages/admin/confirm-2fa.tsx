import { Head, router, useForm } from '@inertiajs/react';
import type { FormEvent } from 'react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import type { AdminTranslations } from '@/types/admin';

export type AdminConfirmTwoFactorProps = {
    locale: 'ar' | 'en';
    direction: 'rtl' | 'ltr';
    adminUi: AdminTranslations;
    confirmUrl: string;
    logoutUrl: string;
};

export default function AdminConfirmTwoFactor({
    locale,
    direction,
    adminUi,
    confirmUrl,
    logoutUrl,
}: AdminConfirmTwoFactorProps) {
    const [usingRecoveryCode, setUsingRecoveryCode] = useState(false);
    const copy = adminUi.confirm2fa ?? {
        headTitle:
            locale === 'ar'
                ? 'التحقق بخطوتين للإدارة'
                : 'Admin Two-Factor Verification',
        title:
            locale === 'ar'
                ? 'تأكيد رمز المصادقة'
                : 'Confirm Authenticator Code',
        description:
            locale === 'ar'
                ? 'أدخل رمز التحقق المكون من 6 أرقام من تطبيق المصادقة للوصول إلى لوحة التحكم.'
                : 'Enter the 6-digit verification code from your authenticator app to access the Admin dashboard.',
        code: locale === 'ar' ? 'رمز تطبيق المصادقة' : 'Authenticator code',
        recoveryCode: locale === 'ar' ? 'رمز الاسترداد' : 'Recovery code',
        useRecoveryCode:
            locale === 'ar' ? 'استخدم رمز استرداد' : 'Use a recovery code',
        useAuthenticatorCode:
            locale === 'ar'
                ? 'استخدم رمز تطبيق المصادقة'
                : 'Use an authenticator code',
        invalidCode:
            locale === 'ar'
                ? 'الرمز غير صحيح أو انتهت صلاحيته.'
                : 'The code is invalid or has expired.',
        invalidRecoveryCode:
            locale === 'ar'
                ? 'رمز الاسترداد غير صحيح أو تم استخدامه.'
                : 'The recovery code is invalid or has already been used.',
        submit: locale === 'ar' ? 'تأكيد والمتابعة' : 'Verify and continue',
        submitting: locale === 'ar' ? 'جاري التحقق…' : 'Verifying…',
        logout: locale === 'ar' ? 'تسجيل الخروج' : 'Log out',
    };

    const form = useForm({
        code: '',
        recovery_code: '',
    });

    const fieldName = usingRecoveryCode ? 'recovery_code' : 'code';
    const fieldLabel = usingRecoveryCode ? copy.recoveryCode : copy.code;

    const handleSubmit = (e: FormEvent<HTMLFormElement>) => {
        e.preventDefault();
        form.post(confirmUrl, {
            preserveScroll: true,
            onSuccess: () => {
                form.reset();
            },
        });
    };

    const toggleMode = () => {
        setUsingRecoveryCode((prev) => !prev);
        form.reset();
        form.clearErrors();
    };

    return (
        <>
            <Head title={copy.headTitle} />

            <section
                className="auth-shell flex min-h-dvh items-center justify-center px-4 py-12"
                aria-labelledby="confirm-2fa-title"
                dir={direction}
            >
                <div className="auth-shell__inner w-full max-w-md">
                    <div className="auth-shell__grid">
                        <div
                            className="auth-shell__brand mb-6 flex justify-center"
                            aria-hidden="true"
                        >
                            <img
                                alt=""
                                height="64"
                                src="/images/arabut-logo-header.webp"
                                width="64"
                                className="h-16 w-16 object-contain"
                            />
                        </div>
                        <article
                            className="auth-shell__form-card"
                            dir={direction}
                        >
                            <div className="auth-shell__heading mb-6">
                                <h1
                                    className="auth-shell__title text-2xl font-bold tracking-tight text-foreground"
                                    id="confirm-2fa-title"
                                >
                                    {copy.title}
                                </h1>
                                <p className="mt-1.5 text-sm text-muted-foreground">
                                    {copy.description}
                                </p>
                            </div>

                            <form onSubmit={handleSubmit} className="space-y-6">
                                <div className="grid gap-2">
                                    <Label htmlFor={fieldName}>
                                        {fieldLabel}
                                    </Label>
                                    <Input
                                        id={fieldName}
                                        key={fieldName}
                                        name={fieldName}
                                        type="text"
                                        inputMode={
                                            usingRecoveryCode
                                                ? 'text'
                                                : 'numeric'
                                        }
                                        autoComplete={
                                            usingRecoveryCode
                                                ? 'off'
                                                : 'one-time-code'
                                        }
                                        autoFocus
                                        required
                                        value={
                                            usingRecoveryCode
                                                ? form.data.recovery_code
                                                : form.data.code
                                        }
                                        onChange={(e) =>
                                            form.setData(
                                                fieldName,
                                                e.target.value,
                                            )
                                        }
                                        className="h-11"
                                        aria-describedby={
                                            form.errors[fieldName]
                                                ? `${fieldName}-error`
                                                : undefined
                                        }
                                        aria-invalid={Boolean(
                                            form.errors[fieldName],
                                        )}
                                    />
                                    <InputError
                                        id={`${fieldName}-error`}
                                        message={
                                            form.errors[fieldName] ??
                                            form.errors.code
                                        }
                                        role="alert"
                                    />
                                </div>

                                <div className="flex items-center justify-between">
                                    <Button
                                        type="button"
                                        variant="link"
                                        className="auth-inline-link min-h-[44px] px-0 text-xs text-muted-foreground hover:text-foreground"
                                        onClick={toggleMode}
                                    >
                                        {usingRecoveryCode
                                            ? copy.useAuthenticatorCode
                                            : copy.useRecoveryCode}
                                    </Button>

                                    <Button
                                        type="button"
                                        variant="link"
                                        className="auth-inline-link min-h-[44px] px-0 text-xs text-muted-foreground hover:text-destructive"
                                        onClick={() => {
                                            router.flushAll();
                                            router.post(logoutUrl);
                                        }}
                                    >
                                        {copy.logout}
                                    </Button>
                                </div>

                                <Button
                                    type="submit"
                                    className="auth-form__submit h-11 w-full font-bold"
                                    disabled={form.processing}
                                >
                                    {form.processing && (
                                        <Spinner className="me-2" />
                                    )}
                                    {form.processing
                                        ? copy.submitting
                                        : copy.submit}
                                </Button>
                            </form>
                        </article>
                    </div>
                </div>
            </section>
        </>
    );
}
