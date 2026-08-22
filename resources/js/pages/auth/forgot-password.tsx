import { Form, Head } from '@inertiajs/react';
import { LoaderCircle } from 'lucide-react';
import InputError from '@/components/input-error';
import TextLink from '@/components/text-link';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { AuthRoutes, AuthUiTranslations } from '@/types/auth';

export default function ForgotPassword({
    authRoutes,
    authUi,
    status,
}: {
    authRoutes: AuthRoutes;
    authUi: AuthUiTranslations;
    status?: string;
}) {
    return (
        <>
            <Head title={authUi.forgot_password.head_title} />

            {status && (
                <div
                    className="auth-form__status"
                    role="status"
                    aria-live="polite"
                >
                    {status}
                </div>
            )}

            <div className="space-y-6">
                <Form
                    action={authRoutes.forgotPasswordStoreUrl}
                    className="auth-form"
                    method="post"
                >
                    {({ processing, errors }) => (
                        <>
                            <div className="grid gap-2">
                                <Label htmlFor="email">
                                    {authUi.fields.email}
                                </Label>
                                <Input
                                    id="email"
                                    type="email"
                                    name="email"
                                    autoComplete="email"
                                    autoFocus
                                    required
                                    placeholder="email@example.com"
                                    className="h-10"
                                    aria-describedby={
                                        errors.email ? 'email-error' : undefined
                                    }
                                    aria-invalid={Boolean(errors.email)}
                                />

                                <InputError
                                    id="email-error"
                                    message={errors.email}
                                    role="alert"
                                />
                            </div>

                            <div className="my-6 flex items-center justify-start">
                                <Button
                                    className="auth-form__submit h-10 w-full"
                                    disabled={processing}
                                    data-test="email-password-reset-link-button"
                                    type="submit"
                                >
                                    {processing && (
                                        <LoaderCircle className="h-4 w-4 animate-spin" />
                                    )}
                                    {authUi.forgot_password.submit}
                                </Button>
                            </div>
                        </>
                    )}
                </Form>

                <div className="auth-form__switch">
                    <span>{authUi.forgot_password.return_prompt}</span>{' '}
                    <TextLink
                        className="auth-inline-link"
                        href={authRoutes.loginUrl}
                    >
                        {authUi.forgot_password.return_link}
                    </TextLink>
                </div>
            </div>
        </>
    );
}
