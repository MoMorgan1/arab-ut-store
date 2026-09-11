import { Head, router, useForm, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import type { FormEvent } from 'react';

import AppIcon from '@/components/account/app-icon';
import InputError from '@/components/input-error';
import OneTimeCodeField from '@/components/one-time-code-field';
import PhoneNumberField from '@/components/phone-number-field';
import { useResendCountdown } from '@/hooks/use-resend-countdown';
import MyAccountLayout from '@/layouts/my-account-layout';
import { splitE164 } from '@/lib/phone-country-codes';
import { cn } from '@/lib/utils';
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
        // A number the parser does not recognise is still masked: only the
        // last four digits ever reach the DOM.
        return `•••${value.slice(-4)}`;
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

    const [isEditingName, setIsEditingName] = useState(false);
    const [editingContact, setEditingContact] = useState<
        'email' | 'phone' | null
    >(null);
    const [phoneCodeSent, setPhoneCodeSent] = useState(
        props.profile.phone.pending !== null,
    );
    const [requestedPhone, setRequestedPhone] = useState(
        props.profile.phone.pending ?? '',
    );
    const [emailPromptDismissed, setEmailPromptDismissed] = useState(false);
    const [isResending, setIsResending] = useState(false);
    const [isEditingPassword, setIsEditingPassword] = useState(false);
    const [passwordSuccess, setPasswordSuccess] = useState(false);
    const countdown = useResendCountdown(60);

    const hasEmail = Boolean(props.profile.email.value);

    const details = useForm({
        first_name: props.profile.firstName,
        last_name: props.profile.lastName,
    });
    const email = useForm({ email: '' });
    const phone = useForm({ phone: '' });
    const phoneCode = useForm({ code: '' });
    const passwordForm = useForm({
        current_password: '',
        password: '',
        password_confirmation: '',
    });
    const resetLink = useForm({});

    phoneCode.dontRemember('code');
    passwordForm.dontRemember(
        'current_password',
        'password',
        'password_confirmation',
    );

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
            onSuccess: () => {
                setIsEditingName(false);
            },
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

    function submitPassword(event: FormEvent) {
        event.preventDefault();

        if (props.security.hasPassword) {
            passwordForm.put(props.securityActions.changeUrl, {
                onError: (errors) => focusFirstError(errors),
                onSuccess: () => {
                    passwordForm.reset();
                    setIsEditingPassword(false);
                    setPasswordSuccess(true);
                },
                preserveScroll: true,
            });
        } else {
            passwordForm.post(props.securityActions.setupUrl, {
                onError: (errors) => focusFirstError(errors),
                onSuccess: () => {
                    passwordForm.reset();
                    setIsEditingPassword(false);
                    setPasswordSuccess(true);
                },
                preserveScroll: true,
            });
        }
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
                </header>

                {!hasEmail && !emailPromptDismissed ? (
                    <div
                        aria-label={
                            props.accountUi.profile.add_email_prompt_title ??
                            (props.locale === 'en'
                                ? 'Add your email address'
                                : 'أضف بريدك الإلكتروني')
                        }
                        className="account-profile-prompt"
                        data-testid="add-email-prompt"
                        role="region"
                    >
                        <AppIcon
                            className="account-profile-prompt__icon"
                            name="mail"
                        />
                        <span className="account-profile-prompt__title">
                            {props.accountUi.profile.add_email_prompt_title ??
                                (props.locale === 'en'
                                    ? 'Add your email address'
                                    : 'أضف بريدك الإلكتروني')}
                        </span>
                        <button
                            className="account-profile-prompt__action"
                            data-testid="trigger-add-email"
                            onClick={() => {
                                setEditingContact('email');
                                window.requestAnimationFrame(() =>
                                    document
                                        .getElementById('new_email')
                                        ?.scrollIntoView({
                                            behavior: 'smooth',
                                            block: 'center',
                                        }),
                                );
                            }}
                            type="button"
                        >
                            {props.accountUi.profile.add_email_prompt_action ??
                                (props.locale === 'en'
                                    ? 'Add email'
                                    : 'إضافة بريد إلكتروني')}
                        </button>
                        <button
                            aria-label={
                                props.accountUi.profile
                                    .add_email_prompt_dismiss ??
                                'Dismiss prompt'
                            }
                            className="account-profile-prompt__dismiss"
                            data-testid="dismiss-email-prompt"
                            onClick={() => setEmailPromptDismissed(true)}
                            type="button"
                        >
                            &times;
                        </button>
                    </div>
                ) : null}

                {/* Card 1: My Details */}
                <section className="account-profile-card">
                    <CardHeader
                        title={props.accountUi.profile.personal_card_title}
                    />

                    {!isEditingName ? (
                        <div className="account-profile-item">
                            {/* One line: icon, then the name, then the action at
                                the far end. A short value does not need the
                                action on its own row below it. */}
                            <div className="account-profile-row account-profile-row--compact">
                                <span
                                    aria-hidden="true"
                                    className="account-profile-row__icon account-profile-row__icon--state"
                                >
                                    <AppIcon name="user" />
                                </span>
                                <div className="account-profile-row__line">
                                    <div className="account-profile-row__info">
                                        <span className="account-profile-row__label">
                                            {props.accountUi.profile.name}
                                        </span>
                                        <strong className="account-profile-row__value">
                                            {`${props.profile.firstName} ${props.profile.lastName}`.trim()}
                                        </strong>
                                    </div>
                                    <button
                                        className="account-profile-row__btn"
                                        onClick={() => setIsEditingName(true)}
                                        type="button"
                                    >
                                        {props.accountUi.profile.edit}
                                    </button>
                                </div>
                            </div>
                        </div>
                    ) : (
                        <form
                            className="account-profile-form"
                            onSubmit={updateDetails}
                        >
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
                            </div>
                            <div className="account-profile-form__actions">
                                <button
                                    className="account-profile-btn--primary"
                                    disabled={details.processing}
                                    type="submit"
                                >
                                    {props.accountUi.profile.save}
                                </button>
                                <button
                                    className="account-profile-btn--ghost"
                                    onClick={() => {
                                        details.reset();
                                        setIsEditingName(false);
                                    }}
                                    type="button"
                                >
                                    {props.accountUi.profile.cancel_edit}
                                </button>
                            </div>
                        </form>
                    )}
                </section>

                {/* Card 2: Contact */}
                <section className="account-profile-card">
                    <CardHeader
                        title={props.accountUi.profile.contact_card_title}
                    />

                    <div className="account-profile-card__rows">
                        {/* WhatsApp row */}
                        <div className="account-profile-item">
                            <div className="account-profile-row">
                                <span
                                    aria-hidden="true"
                                    className={cn(
                                        'account-profile-row__icon',
                                        props.profile.phone.value &&
                                            'account-profile-row__icon--ok',
                                    )}
                                >
                                    <AppIcon name="whatsapp" />
                                </span>
                                <div className="account-profile-row__info">
                                    <span className="account-profile-row__label">
                                        {props.accountUi.profile.phone}
                                    </span>
                                    {props.profile.phone.value ? (
                                        <strong className="account-profile-row__value account-profile-row__value--ltr">
                                            {maskPhoneNumber(
                                                props.profile.phone.value,
                                            )}
                                        </strong>
                                    ) : (
                                        <strong className="account-profile-row__value">
                                            {props.accountUi.profile.not_set}
                                        </strong>
                                    )}
                                </div>

                                <div className="account-profile-row__end">
                                    {/* Nothing to verify before a number
                                        exists, so the badge only appears once
                                        there is one. */}
                                    {props.profile.phone.value &&
                                    !props.profile.phone.verified ? (
                                        <span className="account-profile-badge account-profile-badge--warn">
                                            {props.accountUi.profile.unverified}
                                        </span>
                                    ) : null}
                                    <button
                                        aria-expanded={
                                            editingContact === 'phone'
                                        }
                                        className="account-profile-row__btn"
                                        onClick={() =>
                                            setEditingContact((current) =>
                                                current === 'phone'
                                                    ? null
                                                    : 'phone',
                                            )
                                        }
                                        type="button"
                                    >
                                        {editingContact === 'phone'
                                            ? props.accountUi.profile
                                                  .cancel_edit
                                            : !props.profile.phone.value
                                              ? props.accountUi.profile
                                                    .add_phone
                                              : props.profile.phone.verified
                                                ? props.accountUi.profile.change
                                                : props.accountUi.profile
                                                      .verify_phone}
                                    </button>
                                </div>
                            </div>

                            {props.profile.phone.pending ? (
                                <p className="account-profile-pending">
                                    {props.accountUi.profile.pending_phone}
                                </p>
                            ) : null}

                            {editingContact === 'phone' ? (
                                <div className="account-profile-item__editor">
                                    {!phoneCodeSent ? (
                                        <form
                                            className="account-profile-form"
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
                                                autoComplete="tel"
                                                error={phone.errors.phone}
                                                id="new_phone"
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
                                            <div className="account-profile-form__actions">
                                                <button
                                                    className="account-profile-btn--primary"
                                                    disabled={phone.processing}
                                                    type="submit"
                                                >
                                                    {
                                                        props.accountUi.profile
                                                            .send_phone_code
                                                    }
                                                </button>
                                                <button
                                                    className="account-profile-btn--ghost"
                                                    onClick={() =>
                                                        setEditingContact(null)
                                                    }
                                                    type="button"
                                                >
                                                    {
                                                        props.accountUi.profile
                                                            .cancel_edit
                                                    }
                                                </button>
                                            </div>
                                        </form>
                                    ) : (
                                        <form
                                            className="account-profile-form account-profile-code"
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
                                                autoFocus
                                                disabled={phoneCode.processing}
                                                error={phoneCode.errors.code}
                                                id="code"
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
                                            <div className="account-profile-form__actions">
                                                <button
                                                    className="account-profile-btn--primary"
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
                                                <button
                                                    className="account-profile-btn--ghost"
                                                    onClick={() =>
                                                        setEditingContact(null)
                                                    }
                                                    type="button"
                                                >
                                                    {
                                                        props.accountUi.profile
                                                            .cancel_edit
                                                    }
                                                </button>
                                            </div>
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
                                </div>
                            ) : null}
                        </div>

                        {/* Email row */}
                        <div className="account-profile-item">
                            <div className="account-profile-row">
                                <span
                                    aria-hidden="true"
                                    className="account-profile-row__icon"
                                >
                                    <AppIcon name="mail" />
                                </span>
                                <div className="account-profile-row__info">
                                    <span className="account-profile-row__label">
                                        {props.accountUi.profile.email}
                                    </span>
                                    {/* Only a real address is an LTR run. The
                                        "not added" placeholder is Arabic prose
                                        and must align with the RTL row, the way
                                        the number row's placeholder does. */}
                                    {hasEmail ? (
                                        <strong className="account-profile-row__value account-profile-row__value--ltr">
                                            {props.profile.email.value}
                                        </strong>
                                    ) : (
                                        <strong className="account-profile-row__value">
                                            {props.accountUi.profile.not_set}
                                        </strong>
                                    )}
                                </div>

                                <div className="account-profile-row__end">
                                    {/* Same rule as the number: never claim
                                        "unverified" about an address that does
                                        not exist, and never spend a badge
                                        saying "fine". The badge exists only to
                                        flag what is still outstanding. */}
                                    {hasEmail &&
                                    !props.profile.email.verified ? (
                                        <span className="account-profile-badge account-profile-badge--warn">
                                            {props.accountUi.profile.unverified}
                                        </span>
                                    ) : null}
                                    <button
                                        aria-expanded={
                                            editingContact === 'email'
                                        }
                                        className="account-profile-row__btn"
                                        onClick={() =>
                                            setEditingContact((current) =>
                                                current === 'email'
                                                    ? null
                                                    : 'email',
                                            )
                                        }
                                        type="button"
                                    >
                                        {editingContact === 'email'
                                            ? props.accountUi.profile
                                                  .cancel_edit
                                            : !hasEmail
                                              ? (props.accountUi.profile
                                                    .add_email_prompt_action ??
                                                (props.locale === 'en'
                                                    ? 'Add email'
                                                    : 'إضافة بريد إلكتروني'))
                                              : props.profile.email.verified
                                                ? props.accountUi.profile.change
                                                : props.accountUi.profile
                                                      .verify_email}
                                    </button>
                                </div>
                            </div>

                            {props.profile.email.pending ? (
                                <p className="account-profile-pending">
                                    {props.accountUi.profile.pending_email}
                                </p>
                            ) : null}

                            {editingContact === 'email' ? (
                                <div className="account-profile-item__editor">
                                    <form
                                        className="account-profile-form"
                                        onSubmit={requestEmail}
                                    >
                                        <Field
                                            autocomplete="email"
                                            error={email.errors.email}
                                            id="new_email"
                                            label={
                                                props.accountUi.profile
                                                    .new_email
                                            }
                                            onChange={(value) =>
                                                email.setData('email', value)
                                            }
                                            type="email"
                                            value={email.data.email}
                                        />
                                        <div className="account-profile-form__actions">
                                            <button
                                                className="account-profile-btn--primary"
                                                disabled={email.processing}
                                                type="submit"
                                            >
                                                {
                                                    props.accountUi.profile
                                                        .request_email
                                                }
                                            </button>
                                            <button
                                                className="account-profile-btn--ghost"
                                                onClick={() =>
                                                    setEditingContact(null)
                                                }
                                                type="button"
                                            >
                                                {
                                                    props.accountUi.profile
                                                        .cancel_edit
                                                }
                                            </button>
                                        </div>
                                    </form>
                                </div>
                            ) : null}
                        </div>
                    </div>
                </section>

                {/* Card 3: Password */}
                <section className="account-profile-card">
                    <CardHeader title={props.accountUi.security.card_title} />

                    <div className="account-profile-card__rows">
                        <div className="account-profile-item">
                            <div className="account-profile-row">
                                <span
                                    aria-hidden="true"
                                    className="account-profile-row__icon account-profile-row__icon--state"
                                >
                                    <AppIcon name="lock" />
                                </span>
                                <div className="account-profile-row__info">
                                    <span className="account-profile-row__label">
                                        {props.accountUi.security.card_title}
                                    </span>
                                </div>

                                <div className="account-profile-row__end">
                                    {props.security.hasPassword ||
                                    props.security.canSetPassword ? (
                                        <button
                                            aria-expanded={isEditingPassword}
                                            className="account-profile-row__btn"
                                            onClick={() => {
                                                if (isEditingPassword) {
                                                    setIsEditingPassword(false);
                                                    passwordForm.reset();
                                                } else {
                                                    setIsEditingPassword(true);
                                                    setPasswordSuccess(false);
                                                }
                                            }}
                                            type="button"
                                        >
                                            {isEditingPassword
                                                ? props.accountUi.profile
                                                      .cancel_edit
                                                : props.security.hasPassword
                                                  ? props.accountUi.profile
                                                        .change
                                                  : props.accountUi.security
                                                        .set_password}
                                        </button>
                                    ) : null}
                                </div>
                            </div>

                            {passwordSuccess ? (
                                <p
                                    className="account-profile-success"
                                    role="status"
                                >
                                    {props.accountUi.security.password_changed}
                                </p>
                            ) : null}

                            {isEditingPassword ? (
                                <div className="account-profile-item__editor">
                                    <form
                                        className="account-profile-form"
                                        onSubmit={submitPassword}
                                    >
                                        {props.security.hasPassword ? (
                                            <Field
                                                autocomplete="current-password"
                                                error={
                                                    passwordForm.errors
                                                        .current_password
                                                }
                                                id="current_password"
                                                label={
                                                    props.accountUi.security
                                                        .current_password
                                                }
                                                onChange={(value) =>
                                                    passwordForm.setData(
                                                        'current_password',
                                                        value,
                                                    )
                                                }
                                                type="password"
                                                value={
                                                    passwordForm.data
                                                        .current_password
                                                }
                                            />
                                        ) : null}
                                        <Field
                                            autocomplete="new-password"
                                            error={passwordForm.errors.password}
                                            id="password"
                                            label={
                                                props.accountUi.security
                                                    .new_password
                                            }
                                            onChange={(value) =>
                                                passwordForm.setData(
                                                    'password',
                                                    value,
                                                )
                                            }
                                            type="password"
                                            value={passwordForm.data.password}
                                        />
                                        <Field
                                            autocomplete="new-password"
                                            error={
                                                passwordForm.errors
                                                    .password_confirmation
                                            }
                                            id="password_confirmation"
                                            label={
                                                props.accountUi.security
                                                    .confirm_password
                                            }
                                            onChange={(value) =>
                                                passwordForm.setData(
                                                    'password_confirmation',
                                                    value,
                                                )
                                            }
                                            type="password"
                                            value={
                                                passwordForm.data
                                                    .password_confirmation
                                            }
                                        />
                                        <InputError
                                            message={
                                                passwordForm.errors[
                                                    'error' as keyof typeof passwordForm.errors
                                                ]
                                            }
                                        />
                                        <div className="account-profile-form__actions">
                                            <button
                                                className="account-profile-btn--primary"
                                                disabled={
                                                    passwordForm.processing
                                                }
                                                type="submit"
                                            >
                                                {props.security.hasPassword
                                                    ? props.accountUi.security
                                                          .change_password
                                                    : props.accountUi.security
                                                          .set_password}
                                            </button>
                                            <button
                                                className="account-profile-btn--ghost"
                                                onClick={() => {
                                                    setIsEditingPassword(false);
                                                    passwordForm.reset();
                                                }}
                                                type="button"
                                            >
                                                {
                                                    props.accountUi.profile
                                                        .cancel_edit
                                                }
                                            </button>
                                        </div>
                                    </form>
                                </div>
                            ) : null}

                            {props.security.emailVerified ? (
                                <p className="account-profile-forgot">
                                    <button
                                        className="account-profile-link"
                                        disabled={resetLink.processing}
                                        onClick={() =>
                                            resetLink.post(
                                                props.securityActions
                                                    .resetLinkUrl,
                                                {
                                                    preserveScroll: true,
                                                },
                                            )
                                        }
                                        type="button"
                                    >
                                        {props.accountUi.security.forgot}
                                    </button>
                                </p>
                            ) : null}
                            {resetLink.recentlySuccessful ? (
                                <p
                                    className="account-profile-success"
                                    role="status"
                                >
                                    {props.accountUi.security.reset_link_sent}
                                </p>
                            ) : null}
                        </div>
                    </div>
                </section>

                <button
                    className="account-profile-logout"
                    onClick={logout}
                    type="button"
                >
                    <AppIcon name="logout" />
                    <span>{props.accountUi.navigation.logout}</span>
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

function CardHeader({ title }: { title: string }) {
    return (
        <header className="account-profile-card__header">
            <h3>{title}</h3>
        </header>
    );
}
