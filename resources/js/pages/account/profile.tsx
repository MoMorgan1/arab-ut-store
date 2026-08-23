import { Head, router, useForm, usePage } from '@inertiajs/react';
import {
    CheckCircle2,
    LifeBuoy,
    Mail,
    MessageCircleMore,
    Phone,
    ReceiptText,
    UserRound,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import { useState } from 'react';
import type { FormEvent, ReactNode } from 'react';

import AccountPasswordSection from '@/components/account/account-password-section';
import AccountSectionError from '@/components/account/account-section-error';
import InputError from '@/components/input-error';
import MyAccountLayout from '@/layouts/my-account-layout';
import type { AccountProfilePageProps } from '@/types/account';

export default function AccountProfile() {
    const inertia = usePage<AccountProfilePageProps>();
    const props = inertia.props;
    const [editingContact, setEditingContact] = useState<
        'email' | 'phone' | null
    >(null);
    const [phoneCodeSent, setPhoneCodeSent] = useState(
        props.profile.phone.pending !== null,
    );
    const details = useForm({
        display_currency: props.profile.displayCurrency,
        first_name: props.profile.firstName,
        last_name: props.profile.lastName,
        preferred_locale: props.profile.preferredLocale,
    });
    const email = useForm({ email: '' });
    const phone = useForm({ phone: '' });
    const phoneCode = useForm({ code: '' });

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
        phone.post(props.profileActions.phoneRequestUrl, {
            onError: (errors) =>
                focusFirstError(errors, { phone: 'new_phone' }),
            onSuccess: () => {
                phone.reset();
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
                setEditingContact(null);
            },
            preserveScroll: true,
        });
    }

    function logout() {
        router.flushAll();
        router.post(props.logoutUrl);
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
                    <a href="#contact">
                        {props.accountUi.profile.sections.contact}
                    </a>
                    <a href="#security">
                        {props.accountUi.profile.sections.security}
                    </a>
                    <a href="#support">
                        {props.accountUi.profile.sections.support}
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
                        >
                            {editingContact === 'phone' ? (
                                <>
                                    <form
                                        className="account-profile-contact__editor"
                                        onSubmit={requestPhone}
                                    >
                                        <Field
                                            autocomplete="tel"
                                            error={phone.errors.phone}
                                            id="new_phone"
                                            label={
                                                props.accountUi.profile
                                                    .new_phone
                                            }
                                            onChange={(value) =>
                                                phone.setData('phone', value)
                                            }
                                            type="tel"
                                            value={phone.data.phone}
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
                                    {phoneCodeSent ? (
                                        <form
                                            className="account-profile-contact__editor account-profile-code"
                                            onSubmit={confirmPhone}
                                        >
                                            <Field
                                                autocomplete="one-time-code"
                                                error={phoneCode.errors.code}
                                                id="code"
                                                inputMode="numeric"
                                                label={
                                                    props.accountUi.profile
                                                        .phone_code
                                                }
                                                maxLength={6}
                                                onChange={(value) =>
                                                    phoneCode.setData(
                                                        'code',
                                                        value
                                                            .replace(/\D/g, '')
                                                            .slice(0, 6),
                                                    )
                                                }
                                                value={phoneCode.data.code}
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
                                        </form>
                                    ) : null}
                                </>
                            ) : null}
                        </ContactValue>
                    </div>
                    <p className="account-profile-sensitive-hint">
                        {props.accountUi.profile.sensitive_hint}
                    </p>
                </section>

                <AccountPasswordSection
                    actions={props.securityActions}
                    passwordMode={props.security.passwordMode}
                    passwordRules={props.security.passwordRules}
                    translations={props.accountUi.security}
                />

                <div className="account-security-recovery">
                    <span aria-hidden="true">
                        <LifeBuoy />
                    </span>
                    <div>
                        <h3>{props.accountUi.security.recovery_title}</h3>
                        <p>
                            {props.security.recoveryMode === 'email'
                                ? props.accountUi.security.recovery_email
                                : props.accountUi.security.recovery_whatsapp}
                        </p>
                    </div>
                    {typeof props.security.recoveryUrl === 'string' ? (
                        <a
                            href={props.security.recoveryUrl}
                            rel={
                                props.security.recoveryMode === 'whatsapp'
                                    ? 'noopener noreferrer'
                                    : undefined
                            }
                            target={
                                props.security.recoveryMode === 'whatsapp'
                                    ? '_blank'
                                    : undefined
                            }
                        >
                            {props.accountUi.security.recovery_action}
                        </a>
                    ) : null}
                </div>

                <section
                    className="account-profile-section"
                    id="support"
                    style={{ scrollMarginBlockStart: '5rem' }}
                >
                    <SectionHeading
                        icon={LifeBuoy}
                        title={props.accountUi.support.title}
                    />

                    {props.support.orderNumber ? (
                        <aside className="account-support-context">
                            <ReceiptText aria-hidden="true" />
                            <span>{props.accountUi.support.order_context}</span>
                            <strong>
                                <bdi>{props.support.orderNumber}</bdi>
                            </strong>
                        </aside>
                    ) : null}

                    {!props.support.available ? (
                        <AccountSectionError
                            description={
                                props.accountUi.support.unavailable_description
                            }
                            title={props.accountUi.support.unavailable_title}
                        />
                    ) : (
                        <div className="account-support-grid">
                            {props.support.whatsappUrl ? (
                                <SupportCard
                                    action={
                                        props.accountUi.support.whatsapp_action
                                    }
                                    description={
                                        props.accountUi.support
                                            .whatsapp_description
                                    }
                                    href={props.support.whatsappUrl}
                                    icon={<MessageCircleMore />}
                                    title={
                                        props.accountUi.support.whatsapp_title
                                    }
                                    external
                                />
                            ) : null}
                            {props.support.emailUrl ? (
                                <SupportCard
                                    action={
                                        props.accountUi.support.email_action
                                    }
                                    description={
                                        props.accountUi.support
                                            .email_description
                                    }
                                    href={props.support.emailUrl}
                                    icon={<Mail />}
                                    title={props.accountUi.support.email_title}
                                />
                            ) : null}
                        </div>
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
                <strong>{verification}</strong>
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

function SupportCard({
    action,
    description,
    external = false,
    href,
    icon,
    title,
}: {
    action: string;
    description: string;
    external?: boolean;
    href: string;
    icon: ReactNode;
    title: string;
}) {
    return (
        <article className="account-support-card">
            <span aria-hidden="true">{icon}</span>
            <h3>{title}</h3>
            <p>{description}</p>
            <a
                href={href}
                rel={external ? 'noopener noreferrer' : undefined}
                target={external ? '_blank' : undefined}
            >
                {action}
            </a>
        </article>
    );
}
