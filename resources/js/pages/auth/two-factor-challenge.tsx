import { Form, Head } from '@inertiajs/react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { store } from '@/routes/two-factor/login';
import type { AuthUiTranslations } from '@/types/auth';

type Props = {
    authUi: AuthUiTranslations;
};

export default function TwoFactorChallenge({ authUi }: Props) {
    const [usingRecoveryCode, setUsingRecoveryCode] = useState(false);
    const copy = authUi.two_factor_challenge;
    const fieldName = usingRecoveryCode ? 'recovery_code' : 'code';
    const fieldLabel = usingRecoveryCode ? copy.recovery_code : copy.code;

    return (
        <>
            <Head title={copy.head_title} />

            <Form {...store.form()} className="auth-form" resetOnSuccess>
                {({ processing, errors }) => (
                    <div className="space-y-6">
                        <div className="grid gap-2">
                            <Label htmlFor={fieldName}>{fieldLabel}</Label>
                            <Input
                                id={fieldName}
                                key={fieldName}
                                name={fieldName}
                                type="text"
                                inputMode={
                                    usingRecoveryCode ? 'text' : 'numeric'
                                }
                                autoComplete={
                                    usingRecoveryCode ? 'off' : 'one-time-code'
                                }
                                autoFocus
                                required
                                className="h-11"
                                aria-describedby={
                                    errors[fieldName]
                                        ? `${fieldName}-error`
                                        : undefined
                                }
                                aria-invalid={Boolean(errors[fieldName])}
                            />
                            <InputError
                                id={`${fieldName}-error`}
                                message={errors[fieldName] ?? errors.code}
                                role="alert"
                            />
                        </div>

                        <Button
                            type="button"
                            variant="link"
                            className="auth-inline-link min-h-11 px-0"
                            onClick={() =>
                                setUsingRecoveryCode((current) => !current)
                            }
                        >
                            {usingRecoveryCode
                                ? copy.use_authenticator_code
                                : copy.use_recovery_code}
                        </Button>

                        <Button
                            type="submit"
                            className="auth-form__submit h-11 w-full"
                            disabled={processing}
                        >
                            {processing && <Spinner />}
                            {copy.submit}
                        </Button>
                    </div>
                )}
            </Form>
        </>
    );
}
