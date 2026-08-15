import { Head, useForm, usePage } from '@inertiajs/react';
import { KeyRound, LifeBuoy, ShieldCheck } from 'lucide-react';
import type { FormEvent } from 'react';

import InputError from '@/components/input-error';
import MyAccountLayout from '@/layouts/my-account-layout';
import type { AccountSecurityPageProps } from '@/types/account';

export default function AccountSecurity() {
    const inertia = usePage<AccountSecurityPageProps>();
    const props = inertia.props;
    const translations = props.accountUi.security;
    const form = useForm({
        current_password: '',
        password: '',
        password_confirmation: '',
    });
    const isChange = props.security.passwordMode === 'change';

    form.dontRemember('current_password', 'password', 'password_confirmation');

    function submit(event: FormEvent) {
        event.preventDefault();
        const options = {
            onError: (errors: Record<string, string>) => {
                const first = Object.keys(errors)[0];
                document.getElementById(first)?.focus();
            },
            onSuccess: () => form.reset(),
            preserveScroll: true,
        };

        if (isChange) {
            form.put(props.securityActions.changePasswordUrl, options);
        } else {
            form.post(props.securityActions.setupPasswordUrl, options);
        }
    }

    return (
        <MyAccountLayout {...props} current="security" currentUrl={inertia.url}>
            <Head title={translations.title} />
            <div className="account-security-page">
                <header className="account-page-heading">
                    <p>{props.accountUi.eyebrow}</p>
                    <h2>{translations.title}</h2>
                    <span>{translations.description}</span>
                </header>

                <section className="account-profile-section account-security-section">
                    <header className="account-profile-section__heading">
                        <span aria-hidden="true">
                            <KeyRound />
                        </span>
                        <div>
                            <h3>
                                {isChange
                                    ? translations.change_title
                                    : translations.setup_title}
                            </h3>
                            <p>
                                {isChange
                                    ? translations.change_description
                                    : translations.setup_description}
                            </p>
                        </div>
                    </header>

                    {!isChange ? (
                        <p className="account-security-notice">
                            <ShieldCheck aria-hidden="true" />
                            {translations.social_login_notice}
                        </p>
                    ) : null}

                    <form onSubmit={submit}>
                        {isChange ? (
                            <PasswordField
                                autocomplete="current-password"
                                error={form.errors.current_password}
                                id="current_password"
                                label={translations.current_password}
                                onChange={(value) =>
                                    form.setData('current_password', value)
                                }
                                value={form.data.current_password}
                            />
                        ) : null}
                        <PasswordField
                            autocomplete="new-password"
                            error={form.errors.password}
                            id="password"
                            label={translations.new_password}
                            onChange={(value) =>
                                form.setData('password', value)
                            }
                            passwordRules={props.security.passwordRules}
                            value={form.data.password}
                        />
                        <PasswordField
                            autocomplete="new-password"
                            error={form.errors.password_confirmation}
                            id="password_confirmation"
                            label={translations.confirm_password}
                            onChange={(value) =>
                                form.setData('password_confirmation', value)
                            }
                            passwordRules={props.security.passwordRules}
                            value={form.data.password_confirmation}
                        />
                        <button disabled={form.processing} type="submit">
                            {isChange
                                ? translations.change_password
                                : translations.set_password}
                        </button>
                        {form.recentlySuccessful ? (
                            <p
                                className="account-security-success"
                                role="status"
                            >
                                {translations.password_changed}
                            </p>
                        ) : null}
                    </form>
                </section>

                <section className="account-security-recovery">
                    <span aria-hidden="true">
                        <LifeBuoy />
                    </span>
                    <div>
                        <h3>{translations.recovery_title}</h3>
                        <p>
                            {props.security.recoveryMode === 'email'
                                ? translations.recovery_email
                                : translations.recovery_whatsapp}
                        </p>
                    </div>
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
                        {translations.recovery_action}
                    </a>
                </section>
            </div>
        </MyAccountLayout>
    );
}

function PasswordField({
    autocomplete,
    error,
    id,
    label,
    onChange,
    passwordRules,
    value,
}: {
    autocomplete: string;
    error?: string;
    id: string;
    label: string;
    onChange: (value: string) => void;
    passwordRules?: string;
    value: string;
}) {
    return (
        <label>
            <span>{label}</span>
            <input
                aria-describedby={error ? `${id}-error` : undefined}
                aria-invalid={error ? true : undefined}
                autoComplete={autocomplete}
                id={id}
                onChange={(event) => onChange(event.currentTarget.value)}
                passwordrules={passwordRules}
                type="password"
                value={value}
            />
            <InputError id={`${id}-error`} message={error} />
        </label>
    );
}
