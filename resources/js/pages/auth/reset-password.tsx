import { Form, Head } from '@inertiajs/react';
import InputError from '@/components/input-error';
import PasswordInput from '@/components/password-input';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import type { AuthRoutes, AuthUiTranslations } from '@/types/auth';

type Props = {
    authRoutes: AuthRoutes;
    authUi: AuthUiTranslations;
    token: string;
    email: string;
    passwordRules: string;
};

export default function ResetPassword({
    authRoutes,
    authUi,
    token,
    email,
    passwordRules,
}: Props) {
    return (
        <>
            <Head title={authUi.reset_password.head_title} />

            <Form
                action={authRoutes.resetPasswordStoreUrl}
                method="post"
                transform={(data) => ({ ...data, token, email })}
                resetOnSuccess={['password', 'password_confirmation']}
                className="auth-form"
            >
                {({ processing, errors }) => (
                    <div className="grid gap-6">
                        <div className="grid gap-2">
                            <Label htmlFor="email">{authUi.fields.email}</Label>
                            <Input
                                id="email"
                                type="email"
                                name="email"
                                autoComplete="email"
                                value={email}
                                className="mt-1 block h-11 w-full"
                                readOnly
                                aria-describedby={
                                    errors.email ? 'email-error' : undefined
                                }
                                aria-invalid={Boolean(errors.email)}
                            />
                            <InputError
                                id="email-error"
                                message={errors.email}
                                className="mt-2"
                                role="alert"
                            />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="password">
                                {authUi.fields.password}
                            </Label>
                            <PasswordInput
                                id="password"
                                name="password"
                                autoComplete="new-password"
                                className="mt-1 block h-11 w-full"
                                autoFocus
                                required
                                placeholder={authUi.fields.password}
                                passwordrules={passwordRules}
                                showLabel={authUi.password_visibility.show}
                                hideLabel={authUi.password_visibility.hide}
                                aria-describedby={
                                    errors.password
                                        ? 'password-error'
                                        : undefined
                                }
                                aria-invalid={Boolean(errors.password)}
                            />
                            <InputError
                                id="password-error"
                                message={errors.password}
                                role="alert"
                            />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="password_confirmation">
                                {authUi.fields.password_confirmation}
                            </Label>
                            <PasswordInput
                                id="password_confirmation"
                                name="password_confirmation"
                                autoComplete="new-password"
                                className="mt-1 block h-11 w-full"
                                required
                                placeholder={
                                    authUi.fields.password_confirmation
                                }
                                passwordrules={passwordRules}
                                showLabel={authUi.password_visibility.show}
                                hideLabel={authUi.password_visibility.hide}
                                aria-describedby={
                                    errors.password_confirmation
                                        ? 'password-confirmation-error'
                                        : undefined
                                }
                                aria-invalid={Boolean(
                                    errors.password_confirmation,
                                )}
                            />
                            <InputError
                                id="password-confirmation-error"
                                message={errors.password_confirmation}
                                className="mt-2"
                                role="alert"
                            />
                        </div>

                        <Button
                            type="submit"
                            className="auth-form__submit mt-4 h-11 w-full"
                            disabled={processing}
                            data-test="reset-password-button"
                        >
                            {processing && <Spinner />}
                            {authUi.reset_password.submit}
                        </Button>
                    </div>
                )}
            </Form>
        </>
    );
}
