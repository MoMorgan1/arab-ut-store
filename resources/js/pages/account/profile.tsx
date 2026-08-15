import { Head, useForm, usePage } from '@inertiajs/react';
import { CheckCircle2, Mail, Phone, UserRound } from 'lucide-react';
import { useState } from 'react';
import type { FormEvent } from 'react';

import InputError from '@/components/input-error';
import MyAccountLayout from '@/layouts/my-account-layout';
import type { AccountProfilePageProps } from '@/types/account';

export default function AccountProfile() {
    const inertia = usePage<AccountProfilePageProps>();
    const props = inertia.props;
    const [phoneCodeSent, setPhoneCodeSent] = useState(
        props.profile.phone.pending !== null,
    );
    const details = useForm({
        display_currency: props.profile.displayCurrency,
        first_name: props.profile.firstName,
        last_name: props.profile.lastName,
        preferred_locale: props.profile.preferredLocale,
    });
    const email = useForm({ email: '', current_password: '' });
    const phone = useForm({ phone: '', current_password: '' });
    const phoneCode = useForm({ code: '' });

    email.dontRemember('current_password');
    phone.dontRemember('current_password');
    phoneCode.dontRemember('code');

    function focusFirstError(
        errors: Record<string, string>,
        ids: Record<string, string> = {},
    ) {
        const field = Object.keys(errors)[0];

        if (field) {
            document.getElementById(ids[field] ?? field)?.focus();
        }
    }

    function updateDetails(event: FormEvent) {
        event.preventDefault();
        details.patch(props.profileActions.updateUrl, {
            onError: (errors) =>
                focusFirstError(errors, {
                    current_password: 'email_current_password',
                }),
            preserveScroll: true,
        });
    }

    function requestEmail(event: FormEvent) {
        event.preventDefault();
        email.post(props.profileActions.emailRequestUrl, {
            onError: (errors) =>
                focusFirstError(errors, {
                    current_password: 'phone_current_password',
                }),
            onSuccess: () => email.reset('current_password'),
            preserveScroll: true,
        });
    }

    function requestPhone(event: FormEvent) {
        event.preventDefault();
        phone.post(props.profileActions.phoneRequestUrl, {
            onError: (errors) => focusFirstError(errors),
            onSuccess: () => {
                phone.reset('current_password');
                setPhoneCodeSent(true);
            },
            preserveScroll: true,
        });
    }

    function confirmPhone(event: FormEvent) {
        event.preventDefault();
        phoneCode.post(props.profileActions.phoneConfirmUrl, {
            onError: (errors) => focusFirstError(errors),
            onSuccess: () => {
                phoneCode.reset();
                setPhoneCodeSent(false);
            },
            preserveScroll: true,
        });
    }

    return (
        <MyAccountLayout {...props} current="profile" currentUrl={inertia.url}>
            <Head title={props.accountUi.profile.title} />
            <div className="account-profile-page">
                <header className="account-page-heading">
                    <p>{props.accountUi.eyebrow}</p>
                    <h2>{props.accountUi.profile.title}</h2>
                    <span>{props.accountUi.profile.description}</span>
                </header>

                <section className="account-profile-section">
                    <SectionHeading
                        icon={UserRound}
                        title={props.accountUi.profile.personal_title}
                    />
                    <form onSubmit={updateDetails}>
                        <div className="account-profile-grid">
                            <Field
                                autocomplete="given-name"
                                error={details.errors.first_name}
                                id="first_name"
                                label={props.accountUi.profile.first_name}
                                onChange={(value) =>
                                    details.setData('first_name', value)
                                }
                                value={details.data.first_name}
                            />
                            <Field
                                autocomplete="family-name"
                                error={details.errors.last_name}
                                id="last_name"
                                label={props.accountUi.profile.last_name}
                                onChange={(value) =>
                                    details.setData('last_name', value)
                                }
                                value={details.data.last_name}
                            />
                            <label>
                                <span>
                                    {props.accountUi.profile.preferred_locale}
                                </span>
                                <select
                                    id="preferred_locale"
                                    onChange={(event) =>
                                        details.setData(
                                            'preferred_locale',
                                            event.currentTarget.value as
                                                'ar' | 'en',
                                        )
                                    }
                                    value={details.data.preferred_locale}
                                >
                                    <option value="ar">العربية</option>
                                    <option value="en">English</option>
                                </select>
                                <InputError
                                    message={details.errors.preferred_locale}
                                />
                            </label>
                            <label>
                                <span>
                                    {props.accountUi.profile.display_currency}
                                </span>
                                <select
                                    id="display_currency"
                                    onChange={(event) =>
                                        details.setData(
                                            'display_currency',
                                            event.currentTarget.value,
                                        )
                                    }
                                    value={details.data.display_currency}
                                >
                                    {props.displayCurrencies.map((currency) => (
                                        <option key={currency} value={currency}>
                                            {currency}
                                        </option>
                                    ))}
                                </select>
                                <InputError
                                    message={details.errors.display_currency}
                                />
                            </label>
                        </div>
                        <button disabled={details.processing} type="submit">
                            {props.accountUi.profile.save}
                        </button>
                    </form>
                </section>

                <section className="account-profile-section">
                    <SectionHeading
                        icon={CheckCircle2}
                        title={props.accountUi.profile.contact_title}
                    />
                    <div className="account-profile-contacts">
                        <ContactValue
                            icon={Mail}
                            label={props.accountUi.profile.email}
                            pending={props.profile.email.pending}
                            value={props.profile.email.value}
                            verification={
                                props.profile.email.verified
                                    ? props.accountUi.verification.verified
                                    : props.accountUi.verification.unverified
                            }
                        />
                        <ContactValue
                            icon={Phone}
                            label={props.accountUi.profile.phone}
                            pending={props.profile.phone.pending}
                            value={props.profile.phone.value ?? '—'}
                            verification={
                                props.profile.phone.verified
                                    ? props.accountUi.verification.verified
                                    : props.accountUi.verification.unverified
                            }
                        />
                    </div>
                    <p className="account-profile-sensitive-hint">
                        {props.accountUi.profile.sensitive_hint}
                    </p>

                    <div className="account-profile-change-grid">
                        <form onSubmit={requestEmail}>
                            <h3>{props.accountUi.profile.new_email}</h3>
                            <Field
                                autocomplete="email"
                                error={email.errors.email}
                                id="email"
                                label={props.accountUi.profile.new_email}
                                onChange={(value) =>
                                    email.setData('email', value)
                                }
                                type="email"
                                value={email.data.email}
                            />
                            {props.profile.passwordConfirmationRequired ? (
                                <Field
                                    autocomplete="current-password"
                                    error={email.errors.current_password}
                                    id="email_current_password"
                                    label={
                                        props.accountUi.profile.current_password
                                    }
                                    onChange={(value) =>
                                        email.setData('current_password', value)
                                    }
                                    type="password"
                                    value={email.data.current_password}
                                />
                            ) : null}
                            <button disabled={email.processing} type="submit">
                                {props.accountUi.profile.request_email}
                            </button>
                        </form>

                        <form onSubmit={requestPhone}>
                            <h3>{props.accountUi.profile.new_phone}</h3>
                            <Field
                                autocomplete="tel"
                                error={phone.errors.phone}
                                id="phone"
                                label={props.accountUi.profile.new_phone}
                                onChange={(value) =>
                                    phone.setData('phone', value)
                                }
                                type="tel"
                                value={phone.data.phone}
                            />
                            {props.profile.passwordConfirmationRequired ? (
                                <Field
                                    autocomplete="current-password"
                                    error={phone.errors.current_password}
                                    id="phone_current_password"
                                    label={
                                        props.accountUi.profile.current_password
                                    }
                                    onChange={(value) =>
                                        phone.setData('current_password', value)
                                    }
                                    type="password"
                                    value={phone.data.current_password}
                                />
                            ) : null}
                            <button disabled={phone.processing} type="submit">
                                {props.accountUi.profile.send_phone_code}
                            </button>
                        </form>
                    </div>

                    {phoneCodeSent ? (
                        <form
                            className="account-profile-code"
                            onSubmit={confirmPhone}
                        >
                            <Field
                                autocomplete="one-time-code"
                                error={phoneCode.errors.code}
                                id="code"
                                inputMode="numeric"
                                label={props.accountUi.profile.phone_code}
                                maxLength={6}
                                onChange={(value) =>
                                    phoneCode.setData(
                                        'code',
                                        value.replace(/\D/g, '').slice(0, 6),
                                    )
                                }
                                value={phoneCode.data.code}
                            />
                            <button
                                disabled={
                                    phoneCode.processing ||
                                    phoneCode.data.code.length !== 6
                                }
                                type="submit"
                            >
                                {props.accountUi.profile.confirm_phone}
                            </button>
                        </form>
                    ) : null}
                </section>
            </div>
        </MyAccountLayout>
    );
}

type FieldProps = {
    autocomplete?: string;
    error?: string;
    id: string;
    inputMode?: 'numeric';
    label: string;
    maxLength?: number;
    onChange: (value: string) => void;
    type?: 'email' | 'password' | 'tel' | 'text';
    value: string;
};

function Field({
    autocomplete,
    error,
    id,
    inputMode,
    label,
    maxLength,
    onChange,
    type = 'text',
    value,
}: FieldProps) {
    return (
        <label>
            <span>{label}</span>
            <input
                aria-describedby={error ? `${id}-error` : undefined}
                aria-invalid={error ? true : undefined}
                autoComplete={autocomplete}
                id={id}
                inputMode={inputMode}
                maxLength={maxLength}
                onChange={(event) => onChange(event.currentTarget.value)}
                type={type}
                value={value}
            />
            <InputError id={`${id}-error`} message={error} />
        </label>
    );
}

function SectionHeading({
    icon: Icon,
    title,
}: {
    icon: typeof UserRound;
    title: string;
}) {
    return (
        <header className="account-profile-section__heading">
            <span aria-hidden="true">
                <Icon />
            </span>
            <h3>{title}</h3>
        </header>
    );
}

function ContactValue({
    icon: Icon,
    label,
    pending,
    value,
    verification,
}: {
    icon: typeof Mail;
    label: string;
    pending: string | null;
    value: string;
    verification: string;
}) {
    return (
        <div className="account-profile-contact">
            <span aria-hidden="true">
                <Icon />
            </span>
            <div>
                <p>{label}</p>
                <bdi>{value}</bdi>
                {pending === null ? null : <small>{pending}</small>}
            </div>
            <strong>{verification}</strong>
        </div>
    );
}
