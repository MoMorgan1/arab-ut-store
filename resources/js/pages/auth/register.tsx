import { Form, Head } from '@inertiajs/react';
import { useState } from 'react';

import InputError from '@/components/input-error';
import PasswordInput from '@/components/password-input';
import TextLink from '@/components/text-link';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import type { AuthRoutes, AuthUiTranslations } from '@/types/auth';

type Props = {
    authRoutes: AuthRoutes;
    authUi: AuthUiTranslations;
    passwordRules: string;
};

export default function Register({ authRoutes, authUi, passwordRules }: Props) {
    const [password, setPassword] = useState('');
    const minimumLength = Number(
        passwordRules.match(/minlength:(\d+)/)?.[1] ?? 12,
    );
    const passwordChecks = [
        {
            id: 'minimum',
            label: authUi.register.password_requirements.minimum,
            met: password.length >= minimumLength,
        },
        {
            id: 'mixed-case',
            label: authUi.register.password_requirements.mixed_case,
            met: /[a-z]/.test(password) && /[A-Z]/.test(password),
        },
        {
            id: 'number',
            label: authUi.register.password_requirements.number,
            met: /\d/.test(password),
        },
        {
            id: 'symbol',
            label: authUi.register.password_requirements.symbol,
            met: /[\p{Z}\p{S}\p{P}]/u.test(password),
        },
    ];

    return (
        <>
            <Head title={authUi.register.head_title} />
            <Form
                action={authRoutes.registerStoreUrl}
                method="post"
                resetOnSuccess={['password', 'password_confirmation']}
                disableWhileProcessing
                className="auth-form"
            >
                {({ clearErrors, processing, errors }) => (
                    <>
                        <div className="grid gap-6">
                            <InputError
                                id="verified-phone-error"
                                message={errors.phone}
                                role="alert"
                            />

                            <div className="grid gap-2 sm:grid-cols-2">
                                <div className="grid gap-2">
                                    <Label htmlFor="first_name">
                                        {authUi.fields.first_name}
                                    </Label>
                                    <Input
                                        id="first_name"
                                        type="text"
                                        required
                                        autoFocus
                                        autoComplete="given-name"
                                        name="first_name"
                                        placeholder={authUi.fields.first_name}
                                        className="h-11"
                                        aria-describedby={
                                            errors.first_name
                                                ? 'first-name-error'
                                                : undefined
                                        }
                                        aria-invalid={Boolean(
                                            errors.first_name,
                                        )}
                                    />
                                    <InputError
                                        id="first-name-error"
                                        message={errors.first_name}
                                        className="mt-2"
                                        role="alert"
                                    />
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="last_name">
                                        {authUi.fields.last_name}
                                    </Label>
                                    <Input
                                        id="last_name"
                                        type="text"
                                        required
                                        autoComplete="family-name"
                                        name="last_name"
                                        placeholder={authUi.fields.last_name}
                                        className="h-11"
                                        aria-describedby={
                                            errors.last_name
                                                ? 'last-name-error'
                                                : undefined
                                        }
                                        aria-invalid={Boolean(errors.last_name)}
                                    />
                                    <InputError
                                        id="last-name-error"
                                        message={errors.last_name}
                                        className="mt-2"
                                        role="alert"
                                    />
                                </div>
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="email">
                                    {authUi.fields.email}
                                </Label>
                                <Input
                                    id="email"
                                    type="email"
                                    required
                                    autoComplete="email"
                                    name="email"
                                    placeholder="email@example.com"
                                    className="h-11"
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

                            <div className="grid gap-2">
                                <Label htmlFor="password">
                                    {authUi.fields.password}
                                </Label>
                                <PasswordInput
                                    id="password"
                                    required
                                    autoComplete="new-password"
                                    name="password"
                                    placeholder={authUi.fields.password}
                                    passwordrules={passwordRules}
                                    className="h-11"
                                    onChange={(event) => {
                                        setPassword(event.currentTarget.value);

                                        if (errors.password) {
                                            clearErrors('password');
                                        }
                                    }}
                                    aria-describedby={
                                        errors.password
                                            ? 'password-requirements password-error'
                                            : 'password-requirements'
                                    }
                                    aria-invalid={Boolean(errors.password)}
                                    showLabel={authUi.password_visibility.show}
                                    hideLabel={authUi.password_visibility.hide}
                                />
                                <InputError
                                    id="password-error"
                                    message={errors.password}
                                    role="alert"
                                />
                                <div
                                    className="auth-password-requirements"
                                    id="password-requirements"
                                >
                                    <p>
                                        {
                                            authUi.register
                                                .password_requirements.title
                                        }
                                    </p>
                                    <ul>
                                        {passwordChecks.map((check) => (
                                            <li
                                                data-met={String(check.met)}
                                                key={check.id}
                                            >
                                                <span aria-hidden="true">
                                                    {check.met ? '✓' : '•'}
                                                </span>
                                                {check.label}
                                            </li>
                                        ))}
                                    </ul>
                                </div>
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="password_confirmation">
                                    {authUi.fields.password_confirmation}
                                </Label>
                                <PasswordInput
                                    id="password_confirmation"
                                    required
                                    autoComplete="new-password"
                                    name="password_confirmation"
                                    placeholder={
                                        authUi.fields.password_confirmation
                                    }
                                    passwordrules={passwordRules}
                                    className="h-11"
                                    aria-describedby={
                                        errors.password_confirmation
                                            ? 'password-confirmation-error'
                                            : undefined
                                    }
                                    aria-invalid={Boolean(
                                        errors.password_confirmation,
                                    )}
                                    showLabel={authUi.password_visibility.show}
                                    hideLabel={authUi.password_visibility.hide}
                                />
                                <InputError
                                    id="password-confirmation-error"
                                    message={errors.password_confirmation}
                                    role="alert"
                                />
                            </div>

                            <Button
                                type="submit"
                                className="auth-form__submit mt-2 h-11 w-full"
                                data-test="register-user-button"
                                disabled={processing}
                            >
                                {processing && <Spinner />}
                                {authUi.register.submit}
                            </Button>
                        </div>

                        <div className="auth-form__switch">
                            {authUi.register.login_prompt}{' '}
                            <TextLink
                                className="auth-inline-link"
                                href={authRoutes.loginUrl}
                            >
                                {authUi.register.login_link}
                            </TextLink>
                        </div>
                    </>
                )}
            </Form>
        </>
    );
}
