import { Form, Head } from '@inertiajs/react';
import InputError from '@/components/input-error';
import PasswordInput from '@/components/password-input';
import TextLink from '@/components/text-link';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import type { AuthRoutes, AuthUiTranslations } from '@/types/auth';

type Props = {
    authRoutes: AuthRoutes;
    authUi: AuthUiTranslations;
    status?: string;
    canResetPassword: boolean;
};

export default function Login({
    authRoutes,
    authUi,
    status,
    canResetPassword,
}: Props) {
    return (
        <>
            <Head title={authUi.login.head_title} />

            <Form
                action={authRoutes.loginStoreUrl}
                method="post"
                resetOnSuccess={['password']}
                className="auth-form"
            >
                {({ processing, errors }) => (
                    <>
                        <div className="grid gap-6">
                            <div className="grid gap-2">
                                <Label htmlFor="email">
                                    {authUi.fields.email}
                                </Label>
                                <Input
                                    id="email"
                                    type="email"
                                    name="email"
                                    required
                                    autoFocus
                                    tabIndex={1}
                                    autoComplete="email"
                                    placeholder="email@example.com"
                                    className="h-11"
                                />
                                <InputError message={errors.email} />
                            </div>

                            <div className="grid gap-2">
                                <div className="flex items-center">
                                    <Label htmlFor="password">
                                        {authUi.fields.password}
                                    </Label>
                                    {canResetPassword && (
                                        <TextLink
                                            href={authRoutes.forgotPasswordUrl}
                                            className="auth-inline-link ms-auto"
                                            tabIndex={5}
                                        >
                                            {authUi.login.forgot_password}
                                        </TextLink>
                                    )}
                                </div>
                                <PasswordInput
                                    id="password"
                                    name="password"
                                    required
                                    tabIndex={2}
                                    autoComplete="current-password"
                                    placeholder={authUi.fields.password}
                                    className="h-11"
                                    showLabel={authUi.password_visibility.show}
                                    hideLabel={authUi.password_visibility.hide}
                                />
                                <InputError message={errors.password} />
                            </div>

                            <div className="flex min-h-11 items-center gap-3">
                                <Checkbox
                                    id="remember"
                                    name="remember"
                                    tabIndex={3}
                                />
                                <Label htmlFor="remember">
                                    {authUi.fields.remember}
                                </Label>
                            </div>

                            <Button
                                type="submit"
                                className="mt-4 h-11 w-full"
                                tabIndex={4}
                                disabled={processing}
                                data-test="login-button"
                            >
                                {processing && <Spinner />}
                                {authUi.login.submit}
                            </Button>
                        </div>

                        <div className="auth-form__switch">
                            {authUi.login.registration_prompt}{' '}
                            <TextLink
                                className="auth-inline-link"
                                href={authRoutes.registerUrl}
                                tabIndex={5}
                            >
                                {authUi.login.registration_link}
                            </TextLink>
                        </div>
                    </>
                )}
            </Form>

            {status && (
                <div className="mb-4 text-center text-sm font-medium text-green-600">
                    {status}
                </div>
            )}
        </>
    );
}
