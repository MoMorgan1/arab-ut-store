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
                <div className="mb-4 text-center text-sm font-medium text-green-600">
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
                                    autoComplete="off"
                                    autoFocus
                                    placeholder="email@example.com"
                                    className="h-11"
                                />

                                <InputError message={errors.email} />
                            </div>

                            <div className="my-6 flex items-center justify-start">
                                <Button
                                    className="h-11 w-full"
                                    disabled={processing}
                                    data-test="email-password-reset-link-button"
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
