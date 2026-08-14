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

const countryCodes = [
    { code: '+966', label: '🇸🇦 +966' },
    { code: '+20', label: '🇪🇬 +20' },
    { code: '+971', label: '🇦🇪 +971' },
    { code: '+965', label: '🇰🇼 +965' },
    { code: '+974', label: '🇶🇦 +974' },
    { code: '+973', label: '🇧🇭 +973' },
    { code: '+968', label: '🇴🇲 +968' },
] as const;

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
                                        className="auth-form__submit mt-4 h-11 w-full"
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
                                            {countryCodes.map((country) => (
                                                <option
                                                    key={country.code}
                                                    value={country.code}
                                                >
                                                    {country.label}
                                                </option>
                                            ))}
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
                                        {authUi.login.phone_existing_only}
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
                                <span>{authUi.login.or}</span>
                                <a
                                    className="auth-google-action"
                                    href={authRoutes.googleLoginUrl}
                                >
                                    <svg aria-hidden="true" viewBox="0 0 24 24">
                                        <path
                                            fill="currentColor"
                                            d="M21.6 12.23c0-.71-.06-1.23-.2-1.78H12v3.4h5.52a4.72 4.72 0 0 1-2.05 3.1v2.2h3.32c1.94-1.78 2.81-4.4 2.81-6.92Z"
                                        />
                                        <path
                                            fill="currentColor"
                                            d="M12 22c2.7 0 4.96-.89 6.61-2.42l-3.32-2.2c-.9.6-2.06.96-3.29.96-2.6 0-4.8-1.76-5.6-4.13H2.98v2.27A10 10 0 0 0 12 22Z"
                                        />
                                        <path
                                            fill="currentColor"
                                            d="M6.4 14.21A6 6 0 0 1 6.08 12c0-.77.13-1.52.34-2.21V7.52H2.98A10 10 0 0 0 2 12c0 1.61.39 3.13 1.08 4.48l3.32-2.27Z"
                                        />
                                        <path
                                            fill="currentColor"
                                            d="M12 5.66c1.49 0 2.82.51 3.87 1.5l2.8-2.8A9.45 9.45 0 0 0 12 2a10 10 0 0 0-9.02 5.52L6.42 9.8A5.98 5.98 0 0 1 12 5.66Z"
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
