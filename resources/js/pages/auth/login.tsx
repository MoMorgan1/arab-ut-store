import { Form, Head, router } from '@inertiajs/react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import PasswordInput from '@/components/password-input';
import TextLink from '@/components/text-link';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { phoneCountryCodes } from '@/lib/phone-country-codes';
import {
    sendWhatsAppLoginCode,
    verifyWhatsAppLoginCode,
} from '@/lib/whatsapp-login-api';
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
    const [method, setMethod] = useState<'email' | 'phone'>('email');
    const [countryCode, setCountryCode] = useState('+966');
    const [phoneNumber, setPhoneNumber] = useState('');
    const [phoneCode, setPhoneCode] = useState('');
    const [phoneCodeSent, setPhoneCodeSent] = useState(false);
    const [phoneBusy, setPhoneBusy] = useState(false);
    const [phoneError, setPhoneError] = useState<string | null>(null);
    const internationalPhone = `${countryCode}${phoneNumber.replace(/^0+/, '')}`;

    const sendPhoneCode = async () => {
        setPhoneBusy(true);
        setPhoneError(null);

        try {
            await sendWhatsAppLoginCode(
                authRoutes.whatsappSendUrl,
                internationalPhone,
            );
            setPhoneCodeSent(true);
        } catch (error) {
            setPhoneError(
                error instanceof Error && error.message === 'invalid_code'
                    ? authUi.login.phone_invalid
                    : authUi.login.phone_unavailable,
            );
        } finally {
            setPhoneBusy(false);
        }
    };

    const verifyPhoneCode = async () => {
        setPhoneBusy(true);
        setPhoneError(null);

        try {
            const redirectUrl = await verifyWhatsAppLoginCode(
                authRoutes.whatsappVerifyUrl,
                internationalPhone,
                phoneCode,
            );
            router.visit(redirectUrl);
        } catch (error) {
            setPhoneError(
                error instanceof Error && error.message === 'invalid_code'
                    ? authUi.login.phone_code_invalid
                    : authUi.login.phone_unavailable,
            );
        } finally {
            setPhoneBusy(false);
        }
    };

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
                            <div className="auth-login-method" role="tablist">
                                <button
                                    type="button"
                                    role="tab"
                                    aria-selected={method === 'email'}
                                    className="auth-login-method__tab"
                                    onClick={() => setMethod('email')}
                                >
                                    {authUi.login.email_tab}
                                </button>
                                <button
                                    type="button"
                                    role="tab"
                                    aria-selected={method === 'phone'}
                                    className="auth-login-method__tab"
                                    onClick={() => setMethod('phone')}
                                >
                                    {authUi.login.phone_tab}
                                </button>
                            </div>

                            {method === 'email' ? (
                                <>
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
                                            autoComplete="email"
                                            placeholder="email@example.com"
                                            className="h-11"
                                            aria-describedby={
                                                errors.email
                                                    ? 'email-error'
                                                    : undefined
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
                                        <div className="flex items-center">
                                            <Label htmlFor="password">
                                                {authUi.fields.password}
                                            </Label>
                                            {canResetPassword && (
                                                <TextLink
                                                    href={
                                                        authRoutes.forgotPasswordUrl
                                                    }
                                                    className="auth-inline-link ms-auto"
                                                >
                                                    {
                                                        authUi.login
                                                            .forgot_password
                                                    }
                                                </TextLink>
                                            )}
                                        </div>
                                        <PasswordInput
                                            id="password"
                                            name="password"
                                            required
                                            autoComplete="current-password"
                                            placeholder={authUi.fields.password}
                                            className="h-11"
                                            aria-describedby={
                                                errors.password
                                                    ? 'password-error'
                                                    : undefined
                                            }
                                            aria-invalid={Boolean(
                                                errors.password,
                                            )}
                                            showLabel={
                                                authUi.password_visibility.show
                                            }
                                            hideLabel={
                                                authUi.password_visibility.hide
                                            }
                                        />
                                        <InputError
                                            id="password-error"
                                            message={errors.password}
                                            role="alert"
                                        />
                                    </div>

                                    <div className="flex min-h-11 items-center gap-3">
                                        <Checkbox
                                            id="remember"
                                            name="remember"
                                        />
                                        <Label
                                            className="flex min-h-11 flex-1 cursor-pointer items-center"
                                            htmlFor="remember"
                                        >
                                            {authUi.fields.remember}
                                        </Label>
                                    </div>

                                    <Button
                                        type="submit"
                                        className="auth-form__submit h-11 w-full"
                                        disabled={processing}
                                        data-test="login-button"
                                    >
                                        {processing && <Spinner />}
                                        {authUi.login.submit}
                                    </Button>
                                </>
                            ) : (
                                <div className="auth-whatsapp-login">
                                    <Label htmlFor="phone-number">
                                        {authUi.login.phone_number}
                                    </Label>
                                    <div className="auth-phone-field" dir="ltr">
                                        <label
                                            className="sr-only"
                                            htmlFor="country-code"
                                        >
                                            {authUi.login.country_code}
                                        </label>
                                        <select
                                            id="country-code"
                                            value={countryCode}
                                            disabled={
                                                phoneCodeSent || phoneBusy
                                            }
                                            onChange={(event) =>
                                                setCountryCode(
                                                    event.target.value,
                                                )
                                            }
                                            className="auth-phone-field__country"
                                            aria-label={
                                                authUi.login.country_code
                                            }
                                        >
                                            {phoneCountryCodes.map(
                                                (countryCode) => (
                                                    <option
                                                        key={countryCode}
                                                        value={countryCode}
                                                    >
                                                        {countryCode}
                                                    </option>
                                                ),
                                            )}
                                        </select>
                                        <Input
                                            id="phone-number"
                                            type="tel"
                                            required
                                            autoFocus
                                            autoComplete="tel-national"
                                            inputMode="numeric"
                                            value={phoneNumber}
                                            disabled={
                                                phoneCodeSent || phoneBusy
                                            }
                                            onChange={(event) =>
                                                setPhoneNumber(
                                                    event.target.value.replace(
                                                        /\D/g,
                                                        '',
                                                    ),
                                                )
                                            }
                                            placeholder="501234567"
                                            className="h-11"
                                        />
                                    </div>

                                    <p className="auth-whatsapp-login__note">
                                        {authUi.login.phone_account_hint}
                                    </p>

                                    {phoneCodeSent ? (
                                        <>
                                            <p role="status">
                                                {authUi.login.phone_code_sent}
                                            </p>
                                            <Label htmlFor="phone-code">
                                                {authUi.login.phone_code}
                                            </Label>
                                            <Input
                                                id="phone-code"
                                                type="text"
                                                autoFocus
                                                autoComplete="one-time-code"
                                                inputMode="numeric"
                                                maxLength={6}
                                                value={phoneCode}
                                                onChange={(event) =>
                                                    setPhoneCode(
                                                        event.target.value.replace(
                                                            /\D/g,
                                                            '',
                                                        ),
                                                    )
                                                }
                                                className="h-11"
                                            />
                                            <Button
                                                type="button"
                                                className="auth-form__submit h-11 w-full"
                                                disabled={
                                                    phoneBusy ||
                                                    phoneCode.length !== 6
                                                }
                                                onClick={verifyPhoneCode}
                                            >
                                                {phoneBusy && <Spinner />}
                                                {authUi.login.phone_verify}
                                            </Button>
                                            <button
                                                type="button"
                                                className="auth-inline-link min-h-11"
                                                onClick={() => {
                                                    setPhoneCodeSent(false);
                                                    setPhoneCode('');
                                                    setPhoneError(null);
                                                }}
                                            >
                                                {authUi.login.phone_change}
                                            </button>
                                        </>
                                    ) : (
                                        <Button
                                            type="button"
                                            className="auth-form__submit h-11 w-full"
                                            disabled={
                                                phoneBusy ||
                                                internationalPhone.length < 9
                                            }
                                            onClick={sendPhoneCode}
                                        >
                                            {phoneBusy && <Spinner />}
                                            {authUi.login.phone_send_code}
                                        </Button>
                                    )}

                                    {phoneError && (
                                        <p
                                            role="alert"
                                            className="text-red-400"
                                        >
                                            {phoneError}
                                        </p>
                                    )}
                                </div>
                            )}
                        </div>

                        <div className="auth-form__switch">
                            {authUi.login.registration_prompt}{' '}
                            <TextLink
                                className="auth-inline-link"
                                href={authRoutes.registerUrl}
                            >
                                {authUi.login.registration_link}
                            </TextLink>
                        </div>

                        {authRoutes.googleLoginUrl && (
                            <div className="auth-social-login">
                                <div className="auth-social-login__divider">
                                    <span>{authUi.login.or}</span>
                                </div>
                                <a
                                    className="auth-google-action"
                                    href={authRoutes.googleLoginUrl}
                                >
                                    <svg aria-hidden="true" viewBox="0 0 24 24">
                                        <path
                                            fill="#4285F4"
                                            d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z"
                                        />
                                        <path
                                            fill="#34A853"
                                            d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z"
                                        />
                                        <path
                                            fill="#FBBC05"
                                            d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z"
                                        />
                                        <path
                                            fill="#EA4335"
                                            d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z"
                                        />
                                    </svg>
                                    {authUi.login.google}
                                </a>
                            </div>
                        )}
                    </>
                )}
            </Form>

            {status && (
                <div
                    className="auth-form__status"
                    role="status"
                    aria-live="polite"
                >
                    {status}
                </div>
            )}
        </>
    );
}
