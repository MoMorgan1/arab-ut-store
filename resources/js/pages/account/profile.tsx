import { Head, router, useForm, usePage } from '@inertiajs/react';
import { CheckCircle2, KeyRound, Mail, Phone, UserRound } from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { useEffect, useState } from 'react';
import type { FormEvent, ReactNode } from 'react';

import InputError from '@/components/input-error';
import OneTimeCodeField from '@/components/one-time-code-field';
import PhoneNumberField from '@/components/phone-number-field';
import { useResendCountdown } from '@/hooks/use-resend-countdown';
import MyAccountLayout from '@/layouts/my-account-layout';
import { splitE164 } from '@/lib/phone-country-codes';
import type { AccountProfilePageProps } from '@/types/account';

function renderWithNumber(template: string, number: string) {
    const [before, after] = template.split(':number');

    return (
        <>
            {before}
            <bdi dir="ltr">{number}</bdi>
            {after}
        </>
    );
}

function maskPhoneNumber(value: string): string {
    const split = splitE164(value);

    if (!split) {
        return value;
    }

    const national = split.national;

    if (national.length <= 4) {
        return `${split.dial}•••${national}`;
    }

    return `${split.dial}•••${national.slice(-4)}`;
}

export default function AccountProfile() {
    const inertia = usePage<AccountProfilePageProps>();
    const props = inertia.props;
    const [editingContact, setEditingContact] = useState<
        'email' | 'phone' | null
    >(null);
    const [phoneCodeSent, setPhoneCodeSent] = useState(
        props.profile.phone.pending !== null,
    );
    const [requestedPhone, setRequestedPhone] = useState(
        props.profile.phone.pending ?? '',
    );
    const [isResending, setIsResending] = useState(false);
    const countdown = useResendCountdown(60);

    const details = useForm({
        display_currency: props.profile.displayCurrency,
        first_name: props.profile.firstName,
        last_name: props.profile.lastName,
        preferred_locale: props.profile.preferredLocale,
    });
    const email = useForm({ email: '' });
    const phone = useForm({ phone: '' });
    const phoneCode = useForm({ code: '' });
    const resetLink = useForm({});

    phoneCode.dontRemember('code');

    useEffect(() => {
        if (phoneCodeSent && editingContact === 'phone') {
            document.getElementById('code')?.focus();
        }
    }, [phoneCodeSent, editingContact]);

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
            onError: (errors) => focusFirstError(errors),
            preserveScroll: true,
        });
    }

    function requestEmail(event: FormEvent) {
        event.preventDefault();
        email.post(props.profileActions.emailRequestUrl, {
            onError: (errors) =>
                focusFirstError(errors, { email: 'new_email' }),
            onSuccess: () => {
                email.reset();
                setEditingContact(null);
            },
            preserveScroll: true,
        });
    }

    function requestPhone(event: FormEvent) {
        event.preventDefault();
        const targetPhone = phone.data.phone;
        phone.post(props.profileActions.phoneRequestUrl, {
            onError: (errors) =>
                focusFirstError(errors, { phone: 'new_phone' }),
            onSuccess: () => {
                setRequestedPhone(targetPhone);
                phone.reset();
                setPhoneCodeSent(true);
                countdown.start(60);
            },
            preserveScroll: true,
        });
    }

    function resendPhoneCode() {
        setIsResending(true);
        router.post(
            props.profileActions.phoneRequestUrl,
            { phone: requestedPhone },
            {
                preserveScroll: true,
                onSuccess: () => {
                    countdown.start(60);
                },
                onFinish: () => {
                    setIsResending(false);
                },
            },
        );
    }

    function handleChangeNumber() {
        setPhoneCodeSent(false);
        phoneCode.reset();
        countdown.reset();
    }

    function confirmPhone(event: FormEvent) {
        event.preventDefault();
        phoneCode.post(props.profileActions.phoneConfirmUrl, {
            onError: (errors) => focusFirstError(errors),
            onSuccess: () => {
                phoneCode.reset();
                setPhoneCodeSent(false);
                setEditingContact(null);
                countdown.reset();
            },
            preserveScroll: true,
        });
    }

    function logout() {
        router.flushAll();
        router.post(props.logoutUrl);
    }

    const isContactAttention =
        !props.profile.email.verified || !props.profile.phone.verified;

    function openPhoneVerification() {
        setEditingContact('phone');
        setTimeout(() => {
            const target =
                document.getElementById('new_phone') ??
                document.getElementById('code') ??
                document.getElementById('contact');
            target?.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }, 0);
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

                <nav
                    aria-label={props.accountUi.profile.sections.label}
                    className="account-profile-sections"
                >
                    <a href="#personal">
                        {props.accountUi.profile.sections.personal}
                    </a>
                    <a
                        data-attention={isContactAttention ? 'true' : undefined}
                        href="#contact"
                    >
                        {props.accountUi.profile.sections.contact}
                    </a>
                    <a href="#security">
                        {props.accountUi.profile.sections.security}
                    </a>
                </nav>

                <section
                    className="account-profile-section"
                    id="personal"
                    style={{ scrollMarginBlockStart: '5rem' }}
                >
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
                                    aria-describedby={
                                        details.errors.preferred_locale
                                            ? 'preferred_locale-error'
                                            : undefined
                                    }
                                    aria-invalid={
                                        details.errors.preferred_locale
                                            ? true
                                            : undefined
                                    }
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
                                    id="preferred_locale-error"
                                    message={details.errors.preferred_locale}
                                />
                            </label>
                            <label>
                                <span>
                                    {props.accountUi.profile.display_currency}
                                </span>
                                <select
                                    aria-describedby={
                                        details.errors.display_currency
                                            ? 'display_currency-error'
                                            : undefined
                                    }
                                    aria-invalid={
                                        details.errors.display_currency
                                            ? true
                                            : undefined
                                    }
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
                                    id="display_currency-error"
                                    message={details.errors.display_currency}
                                />
                            </label>
                        </div>
                        <button disabled={details.processing} type="submit">
                            {props.accountUi.profile.save}
                        </button>
                    </form>
                </section>

                <section
                    className="account-profile-section"
                    id="contact"
                    style={{ scrollMarginBlockStart: '5rem' }}
                >
                    <SectionHeading
                        icon={CheckCircle2}
                        title={props.accountUi.profile.contact_title}
                    />
                    <p className="account-profile-sensitive-hint">
                        {props.accountUi.profile.sensitive_hint}
                    </p>
                    {!props.profile.phone.verified &&
                    props.accountUi.profile.verify_phone_cta ? (
                        <button
                            className="account-profile-verify-cta"
                            onClick={openPhoneVerification}
                            type="button"
                        >
                            {props.accountUi.profile.verify_phone_cta}
                        </button>
                    ) : null}
                    <div className="account-profile-contacts">
                        <ContactValue
                            actionLabel={
                                editingContact === 'email'
                                    ? props.accountUi.profile.cancel_edit
                                    : props.accountUi.profile.edit_email
                            }
                            editing={editingContact === 'email'}
                            icon={Mail}
                            label={props.accountUi.profile.email}
                            onEdit={() =>
                                setEditingContact((current) =>
                                    current === 'email' ? null : 'email',
                                )
                            }
                            pending={props.profile.email.pending}
                            value={props.profile.email.value}
                            verification={
                                props.profile.email.verified
                                    ? props.accountUi.verification.verified
                                    : props.accountUi.verification.unverified
                            }
                            verified={props.profile.email.verified}
                        >
                            {editingContact === 'email' ? (
                                <form
                                    className="account-profile-contact__editor"
                                    onSubmit={requestEmail}
                                >
                                    <Field
                                        autocomplete="email"
                                        error={email.errors.email}
                                        id="new_email"
                                        label={
                                            props.accountUi.profile.new_email
                                        }
                                        onChange={(value) =>
                                            email.setData('email', value)
                                        }
                                        type="email"
                                        value={email.data.email}
                                    />
                                    <button
                                        disabled={email.processing}
                                        type="submit"
                                    >
                                        {props.accountUi.profile.request_email}
                                    </button>
                                </form>
                            ) : null}
                        </ContactValue>
                        <ContactValue
                            actionLabel={
                                editingContact === 'phone'
                                    ? props.accountUi.profile.cancel_edit
                                    : props.accountUi.profile.edit_phone
                            }
                            editing={editingContact === 'phone'}
                            icon={Phone}
                            label={props.accountUi.profile.phone}
                            onEdit={() =>
                                setEditingContact((current) =>
                                    current === 'phone' ? null : 'phone',
                                )
                            }
                            pending={props.profile.phone.pending}
                            value={props.profile.phone.value ?? '—'}
                            verification={
                                props.profile.phone.verified
                                    ? props.accountUi.verification.verified
                                    : props.accountUi.verification.unverified
                            }
                            verified={props.profile.phone.verified}
                        >
                            {editingContact === 'phone' ? (
                                <>
                                    {!phoneCodeSent ? (
                                        <form
                                            className="account-profile-contact__editor"
                                            onSubmit={requestPhone}
                                        >
                                            <label htmlFor="new_phone">
                                                <span>
                                                    {
                                                        props.accountUi.profile
                                                            .new_phone
                                                    }
                                                </span>
                                            </label>
                                            <PhoneNumberField
                                                id="new_phone"
                                                autoComplete="tel"
                                                error={phone.errors.phone}
                                                labels={{
                                                    country:
                                                        props.accountUi.profile
                                                            .phone,
                                                    number: props.accountUi
                                                        .profile.new_phone,
                                                }}
                                                locale={props.locale}
                                                onChange={(value) =>
                                                    phone.setData(
                                                        'phone',
                                                        value,
                                                    )
                                                }
                                                value={phone.data.phone}
                                            />
                                            <InputError
                                                id="new_phone-error"
                                                message={phone.errors.phone}
                                            />
                                            <button
                                                disabled={phone.processing}
                                                type="submit"
                                            >
                                                {
                                                    props.accountUi.profile
                                                        .send_phone_code
                                                }
                                            </button>
                                        </form>
                                    ) : (
                                        <form
                                            className="account-profile-contact__editor account-profile-code"
                                            onSubmit={confirmPhone}
                                        >
                                            <p
                                                className="account-profile-code__sent-to"
                                                role="status"
                                            >
                                                {renderWithNumber(
                                                    props.accountUi.profile
                                                        .phone_code_sent_to,
                                                    maskPhoneNumber(
                                                        requestedPhone ||
                                                            props.profile.phone
                                                                .pending ||
                                                            '',
                                                    ),
                                                )}
                                            </p>
                                            <OneTimeCodeField
                                                id="code"
                                                autoFocus
                                                disabled={phoneCode.processing}
                                                error={phoneCode.errors.code}
                                                label={
                                                    props.accountUi.profile
                                                        .phone_code
                                                }
                                                name="code"
                                                onChange={(value) =>
                                                    phoneCode.setData(
                                                        'code',
                                                        value,
                                                    )
                                                }
                                                value={phoneCode.data.code}
                                            />
                                            <InputError
                                                id="code-error"
                                                message={phoneCode.errors.code}
                                            />
                                            <button
                                                disabled={
                                                    phoneCode.processing ||
                                                    phoneCode.data.code
                                                        .length !== 6
                                                }
                                                type="submit"
                                            >
                                                {
                                                    props.accountUi.profile
                                                        .confirm_phone
                                                }
                                            </button>
                                            <div className="account-profile-code__actions">
                                                {countdown.isActive ? (
                                                    <p
                                                        className="account-profile-code__resend-countdown"
                                                        role="status"
                                                    >
                                                        {(
                                                            props.accountUi
                                                                .profile
                                                                .phone_resend_in ??
                                                            (props.locale ===
                                                            'ar'
                                                                ? 'إعادة الإرسال بعد :seconds ثانية'
                                                                : 'Resend code in :seconds s')
                                                        ).replace(
                                                            ':seconds',
                                                            String(
                                                                countdown.countdown,
                                                            ),
                                                        )}
                                                    </p>
                                                ) : (
                                                    <button
                                                        className="account-profile-code__resend-btn"
                                                        disabled={isResending}
                                                        onClick={
                                                            resendPhoneCode
                                                        }
                                                        type="button"
                                                    >
                                                        {props.accountUi.profile
                                                            .phone_resend ??
                                                            (props.locale ===
                                                            'ar'
                                                                ? 'إعادة إرسال الكود'
                                                                : 'Resend code')}
                                                    </button>
                                                )}
                                                <button
                                                    className="account-profile-code__change-btn"
                                                    onClick={handleChangeNumber}
                                                    type="button"
                                                >
                                                    {props.accountUi.profile
                                                        .phone_change_number ??
                                                        (props.locale === 'ar'
                                                            ? 'تغيير الرقم'
                                                            : 'Change number')}
                                                </button>
                                            </div>
                                        </form>
                                    )}
                                </>
                            ) : null}
                        </ContactValue>
                    </div>
                </section>

                <section
                    className="account-profile-section"
                    id="security"
                    style={{ scrollMarginBlockStart: '5rem' }}
                >
                    <SectionHeading
                        icon={KeyRound}
                        title={props.accountUi.security.title}
                    />
                    <p>{props.accountUi.security.reset_link_description}</p>
                    {props.security.emailVerified ? (
                        <>
                            <button
                                className="account-security-reset"
                                disabled={resetLink.processing}
                                onClick={() =>
                                    resetLink.post(
                                        props.securityActions.resetLinkUrl,
                                        {
                                            preserveScroll: true,
                                        },
                                    )
                                }
                                type="button"
                            >
                                {props.accountUi.security.reset_link_button}
                            </button>
                            {resetLink.recentlySuccessful ? (
                                <p
                                    className="account-security-success"
                                    role="status"
                                >
                                    {props.accountUi.security.reset_link_sent}
                                </p>
                            ) : null}
                        </>
                    ) : (
                        <p className="account-security-notice">
                            <span>
                                {
                                    props.accountUi.security
                                        .reset_link_needs_email
                                }
                            </span>
                            {props.storeShell.whatsappUrl ? (
                                <a
                                    href={props.storeShell.whatsappUrl}
                                    rel="noopener noreferrer"
                                    target="_blank"
                                >
                                    {
                                        props.accountUi.security
                                            .reset_link_support
                                    }
                                </a>
                            ) : null}
                        </p>
                    )}
                </section>

                <button
                    className="account-profile-logout"
                    onClick={logout}
                    type="button"
                >
                    {props.accountUi.navigation.logout}
                </button>
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
    icon: LucideIcon;
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
    actionLabel,
    children,
    editing,
    icon: Icon,
    label,
    onEdit,
    pending,
    value,
    verification,
    verified,
}: {
    actionLabel: string;
    children?: ReactNode;
    editing: boolean;
    icon: LucideIcon;
    label: string;
    onEdit: () => void;
    pending: string | null;
    value: string;
    verification: string;
    verified: boolean;
}) {
    return (
        <div
            className={[
                'account-profile-contact',
                editing ? 'is-editing' : null,
            ]
                .filter(Boolean)
                .join(' ')}
        >
            <div className="account-profile-contact__summary">
                <span aria-hidden="true">
                    <Icon />
                </span>
                <div>
                    <p>{label}</p>
                    <bdi>{value}</bdi>
                    {pending === null ? null : <small>{pending}</small>}
                </div>
                <strong data-state={verified ? 'verified' : 'unverified'}>
                    {verification}
                </strong>
                <button
                    aria-expanded={editing}
                    className="account-profile-contact__edit"
                    onClick={onEdit}
                    type="button"
                >
                    {actionLabel}
                </button>
            </div>
            {children}
        </div>
    );
}
