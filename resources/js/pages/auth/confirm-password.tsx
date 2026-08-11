import { Form, Head } from '@inertiajs/react';
import InputError from '@/components/input-error';
import PasswordInput from '@/components/password-input';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { store } from '@/routes/password/confirm';
import type { AuthUiTranslations } from '@/types/auth';

export default function ConfirmPassword({
    authUi,
}: {
    authUi: AuthUiTranslations;
}) {
    return (
        <>
            <Head title={authUi.confirm_password.head_title} />

            <Form
                {...store.form()}
                className="auth-form"
                resetOnSuccess={['password']}
            >
                {({ processing, errors }) => (
                    <div className="space-y-6">
                        <div className="grid gap-2">
                            <Label htmlFor="password">
                                {authUi.fields.password}
                            </Label>
                            <PasswordInput
                                id="password"
                                name="password"
                                placeholder={authUi.fields.password}
                                autoComplete="current-password"
                                autoFocus
                                required
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

                        <div className="flex items-center">
                            <Button
                                className="auth-form__submit h-11 w-full"
                                disabled={processing}
                                data-test="confirm-password-button"
                            >
                                {processing && <Spinner />}
                                {authUi.confirm_password.submit}
                            </Button>
                        </div>
                    </div>
                )}
            </Form>
        </>
    );
}
