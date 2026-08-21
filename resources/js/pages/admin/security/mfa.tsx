import { Head, router } from '@inertiajs/react';
import {
    AlertTriangle,
    CheckCircle2,
    KeyRound,
    LoaderCircle,
    RotateCcw,
    ShieldCheck,
} from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';

import {
    AdminMfaApiError,
    confirmAdminMfa,
    enableAdminMfa,
    loadAdminMfaQrCode,
    loadAdminMfaRecoveryCodes,
    regenerateAdminMfaRecoveryCodes,
} from '@/lib/admin-mfa-api';
import type { AdminMfaPageProps } from '@/types/admin';

type Operation = 'enable' | 'confirm' | 'recovery' | 'regenerate' | null;
type RetryOperation =
    | 'loadQrCode'
    | 'startEnrollment'
    | 'confirmEnrollment'
    | 'showRecoveryCodes'
    | 'regenerateRecoveryCodes';
type FailureRecovery = 'retry' | 'login' | 'home' | 'reauthenticate' | 'wait';
type FailureState = { message: string; recovery: FailureRecovery };

export default function AdminMfaPage({
    adminUi,
    direction,
    locale,
    mfa,
}: AdminMfaPageProps) {
    const copy = adminUi.mfa;
    const mounted = useRef(true);
    const retry = useRef<RetryOperation | null>(null);
    const [enabled, setEnabled] = useState(mfa.enabled);
    const [confirmed, setConfirmed] = useState(mfa.confirmed);
    const [code, setCode] = useState('');
    const [codeError, setCodeError] = useState<string | null>(null);
    const [qrSvg, setQrSvg] = useState<string | null>(null);
    const [recoveryCodes, setRecoveryCodes] = useState<string[] | null>(null);
    const [confirmingRegeneration, setConfirmingRegeneration] = useState(false);
    const [operation, setOperation] = useState<Operation>(null);
    const [failure, setFailure] = useState<FailureState | null>(null);

    const clearSensitiveState = useCallback(() => {
        setCode('');
        setCodeError(null);
        setQrSvg(null);
        setRecoveryCodes(null);
        setConfirmingRegeneration(false);
    }, []);

    const run = useCallback(
        async (
            currentOperation: Exclude<Operation, null>,
            retryOperation: RetryOperation,
            action: () => Promise<void>,
        ): Promise<boolean> => {
            retry.current = retryOperation;
            setFailure(null);
            setCodeError(null);
            setOperation(currentOperation);

            try {
                await action();

                return true;
            } catch (error) {
                if (mounted.current) {
                    if (
                        currentOperation === 'confirm' &&
                        error instanceof AdminMfaApiError &&
                        error.code === 'validation'
                    ) {
                        setCodeError(copy.invalidCode);
                    } else if (error instanceof AdminMfaApiError) {
                        setFailure(matchFailure(error.code, copy));
                    } else {
                        setFailure({
                            message: copy.failed,
                            recovery: 'retry',
                        });
                    }
                }

                return false;
            } finally {
                if (mounted.current) {
                    setOperation(null);
                }
            }
        },
        [copy],
    );

    const loadQrCode = useCallback(async (): Promise<void> => {
        await run('enable', 'loadQrCode', async () => {
            const qr = await loadAdminMfaQrCode(mfa.routes.qrCode);

            if (mounted.current) {
                setQrSvg(qr.svg);
            }
        });
    }, [mfa.routes.qrCode, run]);

    const startEnrollment = useCallback(async (): Promise<void> => {
        await run('enable', 'startEnrollment', async () => {
            await enableAdminMfa(mfa.routes.enable);
            const qr = await loadAdminMfaQrCode(mfa.routes.qrCode);

            if (mounted.current) {
                setEnabled(true);
                setQrSvg(qr.svg);
            }
        });
    }, [mfa.routes.enable, mfa.routes.qrCode, run]);

    const confirmEnrollment = useCallback(async (): Promise<void> => {
        await run('confirm', 'confirmEnrollment', async () => {
            await confirmAdminMfa(mfa.routes.confirm, code);

            if (mounted.current) {
                setConfirmed(true);
                setCode('');
                setQrSvg(null);
            }
        });
    }, [code, mfa.routes.confirm, run]);

    const showRecoveryCodes = useCallback(async (): Promise<void> => {
        await run('recovery', 'showRecoveryCodes', async () => {
            const codes = await loadAdminMfaRecoveryCodes(
                mfa.routes.recoveryCodes,
            );

            if (mounted.current) {
                setRecoveryCodes(codes);
            }
        });
    }, [mfa.routes.recoveryCodes, run]);

    const regenerateRecoveryCodes = useCallback(async (): Promise<void> => {
        const regenerated = await run(
            'regenerate',
            'regenerateRecoveryCodes',
            async () => {
                await regenerateAdminMfaRecoveryCodes(
                    mfa.routes.regenerateRecoveryCodes,
                );

                if (mounted.current) {
                    setRecoveryCodes(null);
                    setConfirmingRegeneration(false);
                }
            },
        );

        if (regenerated && mounted.current) {
            await showRecoveryCodes();
        }
    }, [mfa.routes.regenerateRecoveryCodes, run, showRecoveryCodes]);

    useEffect(() => {
        if (
            enabled &&
            !confirmed &&
            qrSvg === null &&
            operation === null &&
            failure === null
        ) {
            void loadQrCode();
        }
    }, [confirmed, enabled, failure, loadQrCode, operation, qrSvg]);

    useEffect(() => {
        mounted.current = true;
        const stopListening = router.on('before', () => {
            clearSensitiveState();
        });

        return () => {
            mounted.current = false;
            retry.current = null;
            stopListening();
        };
    }, [clearSensitiveState]);

    const accountSecurityUrl =
        locale === 'en' ? '/en/my-account/security' : '/my-account/security';
    const blocksMfaActions = failure !== null && failure.recovery !== 'retry';

    return (
        <main
            className="min-h-dvh touch-manipulation overflow-x-hidden bg-[var(--arabut-navy-deep)] pt-[max(2rem,env(safe-area-inset-top))] pr-[max(1rem,env(safe-area-inset-right))] pb-[max(2rem,env(safe-area-inset-bottom))] pl-[max(1rem,env(safe-area-inset-left))] text-[var(--arabut-ink)] [color-scheme:dark] sm:px-6 sm:py-12"
            dir={direction}
        >
            <Head title={copy.headTitle}>
                <meta content="#080705" name="theme-color" />
            </Head>
            <div className="mx-auto flex w-full max-w-3xl flex-col gap-8">
                <header className="flex items-center justify-between gap-4 border-b border-[var(--arabut-line)] pb-5">
                    <div className="flex min-w-0 items-center gap-3">
                        <img
                            alt={adminUi.brand}
                            className="size-12 shrink-0 object-contain"
                            height="48"
                            src="/images/arabut-logo-header.webp"
                            width="48"
                        />
                        <div className="min-w-0">
                            <p className="text-sm font-semibold text-[var(--arabut-gold-bright)]">
                                {copy.eyebrow}
                            </p>
                            <p
                                className="truncate text-base font-bold"
                                translate="no"
                            >
                                {adminUi.brand}
                            </p>
                        </div>
                    </div>
                    <ShieldCheck
                        aria-hidden="true"
                        className="size-7 shrink-0 text-[var(--arabut-gold)]"
                        strokeWidth={1.7}
                    />
                </header>

                <section
                    aria-labelledby="admin-mfa-title"
                    className="space-y-8"
                >
                    <header className="max-w-2xl space-y-3">
                        <h1
                            className="font-display text-3xl leading-tight font-bold text-balance sm:text-4xl"
                            id="admin-mfa-title"
                        >
                            {copy.title}
                        </h1>
                        <p className="max-w-[65ch] text-base leading-7 text-[var(--arabut-muted)]">
                            {copy.description}
                        </p>
                    </header>

                    {failure ? (
                        <div
                            className="flex flex-col gap-4 border border-[color:var(--arabut-danger)] bg-[var(--arabut-navy-raised)] p-4 sm:flex-row sm:items-center sm:justify-between"
                            role="alert"
                        >
                            <p className="flex items-start gap-3 text-base leading-6 text-[var(--arabut-danger)]">
                                <AlertTriangle
                                    aria-hidden="true"
                                    className="mt-0.5 size-5 shrink-0"
                                />
                                {failure.message}
                            </p>
                            <FailureAction
                                copy={copy}
                                failure={failure}
                                locale={locale}
                                onRetry={() => {
                                    if (retry.current === 'loadQrCode') {
                                        void loadQrCode();
                                    } else if (
                                        retry.current === 'startEnrollment'
                                    ) {
                                        void startEnrollment();
                                    } else if (
                                        retry.current === 'confirmEnrollment'
                                    ) {
                                        void confirmEnrollment();
                                    } else if (
                                        retry.current === 'showRecoveryCodes'
                                    ) {
                                        void showRecoveryCodes();
                                    } else if (
                                        retry.current ===
                                        'regenerateRecoveryCodes'
                                    ) {
                                        void regenerateRecoveryCodes();
                                    }
                                }}
                                retryLabel={adminUi.common.retry}
                            />
                        </div>
                    ) : null}

                    {blocksMfaActions ? null : !mfa.passwordConfigured ? (
                        <PasswordSetup
                            action={copy.openAccountSecurity}
                            description={copy.setupPasswordDescription}
                            title={copy.setupPassword}
                            url={accountSecurityUrl}
                        />
                    ) : confirmed ? (
                        <ConfirmedState
                            cancelLabel={adminUi.common.cancel}
                            confirmingRegeneration={confirmingRegeneration}
                            copy={copy}
                            loading={operation}
                            onConfirmRegeneration={() =>
                                void regenerateRecoveryCodes()
                            }
                            onCancelRegeneration={() =>
                                setConfirmingRegeneration(false)
                            }
                            onHideCodes={() => setRecoveryCodes(null)}
                            onRequestRegeneration={() =>
                                setConfirmingRegeneration(true)
                            }
                            onShowCodes={() => void showRecoveryCodes()}
                            recoveryCodes={recoveryCodes}
                        />
                    ) : enabled ? (
                        <ConfirmationState
                            code={code}
                            codeError={codeError}
                            copy={copy}
                            loading={operation}
                            onCodeChange={setCode}
                            onConfirm={() => void confirmEnrollment()}
                            qrSvg={qrSvg}
                        />
                    ) : (
                        <StartState
                            copy={copy}
                            loading={operation === 'enable'}
                            onEnable={() => void startEnrollment()}
                        />
                    )}
                </section>
            </div>
        </main>
    );
}

type MfaCopy = AdminMfaPageProps['adminUi']['mfa'];

function matchFailure(
    code: AdminMfaApiError['code'],
    copy: MfaCopy,
): FailureState {
    if (code === 'unauthenticated') {
        return { message: copy.sessionExpired, recovery: 'login' };
    }

    if (code === 'forbidden') {
        return { message: copy.accessDenied, recovery: 'home' };
    }

    if (code === 'password_confirmation_required') {
        return {
            message: copy.passwordConfirmationExpired,
            recovery: 'reauthenticate',
        };
    }

    if (code === 'rate_limited') {
        return { message: copy.rateLimited, recovery: 'wait' };
    }

    return { message: copy.failed, recovery: 'retry' };
}

function FailureAction({
    copy,
    failure,
    locale,
    onRetry,
    retryLabel,
}: {
    copy: MfaCopy;
    failure: FailureState;
    locale: 'ar' | 'en';
    onRetry: () => void;
    retryLabel: string;
}) {
    if (failure.recovery === 'retry') {
        return (
            <ActionButton
                label={retryLabel}
                onClick={onRetry}
                variant="secondary"
            />
        );
    }

    const english = locale === 'en';
    const mfaUrl = english ? '/en/admin/security/mfa' : '/admin/security/mfa';

    if (failure.recovery === 'wait') {
        return <FailureLink href={mfaUrl} label={copy.retryAfterWait} />;
    }

    const destinations = {
        home: english ? '/en' : '/',
        login: english ? '/en/login' : '/login',
        reauthenticate: mfaUrl,
    };
    const labels = {
        home: copy.returnToStore,
        login: copy.signIn,
        reauthenticate: copy.confirmPasswordAgain,
    };

    return (
        <FailureLink
            href={destinations[failure.recovery]}
            label={labels[failure.recovery]}
        />
    );
}

function FailureLink({ href, label }: { href: string; label: string }) {
    return (
        <a
            className="inline-flex min-h-11 touch-manipulation items-center justify-center rounded-md border border-[var(--arabut-line)] bg-[var(--arabut-navy-raised)] px-5 py-2 text-sm font-bold text-[var(--arabut-ink)] transition-colors hover:border-[var(--arabut-gold)] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--arabut-focus)] active:brightness-95"
            href={href}
        >
            {label}
        </a>
    );
}

function StartState({
    copy,
    loading,
    onEnable,
}: {
    copy: MfaCopy;
    loading: boolean;
    onEnable: () => void;
}) {
    return (
        <section className="grid gap-6 border-y border-[var(--arabut-line)] py-7 sm:grid-cols-[auto_1fr] sm:items-start">
            <StateIcon icon={KeyRound} />
            <div className="space-y-5">
                <div className="max-w-xl space-y-2">
                    <h2 className="text-xl font-bold">{copy.startTitle}</h2>
                    <p className="text-base leading-7 text-[var(--arabut-muted)]">
                        {copy.startDescription}
                    </p>
                </div>
                <ActionButton
                    label={loading ? copy.enabling : copy.enable}
                    loading={loading}
                    onClick={onEnable}
                />
            </div>
        </section>
    );
}

function ConfirmationState({
    code,
    codeError,
    copy,
    loading,
    onCodeChange,
    onConfirm,
    qrSvg,
}: {
    code: string;
    codeError: string | null;
    copy: MfaCopy;
    loading: Operation;
    onCodeChange: (code: string) => void;
    onConfirm: () => void;
    qrSvg: string | null;
}) {
    return (
        <section className="grid gap-8 border-y border-[var(--arabut-line)] py-7 md:grid-cols-[12rem_1fr] md:items-start">
            <div className="flex aspect-square w-48 max-w-full items-center justify-center bg-[#f5f0e4] p-3">
                {qrSvg ? (
                    <img
                        alt={copy.qrAlt}
                        className="size-full"
                        height="192"
                        src={`data:image/svg+xml;charset=utf-8,${encodeURIComponent(qrSvg)}`}
                        width="192"
                    />
                ) : (
                    <LoaderCircle
                        aria-label={copy.enabling}
                        className="size-8 animate-spin text-[var(--arabut-navy)] motion-reduce:animate-none"
                    />
                )}
            </div>
            <div className="space-y-5">
                <div className="space-y-2">
                    <h2 className="text-xl font-bold">{copy.scanTitle}</h2>
                    <p className="text-base leading-7 text-[var(--arabut-muted)]">
                        {copy.scanDescription}
                    </p>
                </div>
                <label className="grid max-w-sm gap-2" htmlFor="admin-mfa-code">
                    <span className="text-sm font-semibold">
                        {copy.confirmCode}
                    </span>
                    <input
                        aria-describedby={
                            codeError ? 'admin-mfa-code-error' : undefined
                        }
                        aria-invalid={codeError ? true : undefined}
                        autoComplete="one-time-code"
                        className="min-h-11 rounded-md border border-[var(--arabut-line)] bg-[var(--arabut-navy-raised)] px-4 text-center text-lg tracking-[0.35em] tabular-nums transition-colors outline-none focus-visible:border-[var(--arabut-focus)] focus-visible:ring-2 focus-visible:ring-[var(--arabut-focus)] focus-visible:ring-offset-2 focus-visible:ring-offset-[var(--arabut-navy-deep)]"
                        dir="ltr"
                        id="admin-mfa-code"
                        inputMode="numeric"
                        maxLength={6}
                        name="code"
                        onChange={(event) =>
                            onCodeChange(
                                event.currentTarget.value
                                    .replace(/\D/g, '')
                                    .slice(0, 6),
                            )
                        }
                        pattern="[0-9]{6}"
                        spellCheck={false}
                        value={code}
                    />
                    {codeError ? (
                        <span
                            className="text-sm leading-6 text-[var(--arabut-danger)]"
                            id="admin-mfa-code-error"
                            role="alert"
                        >
                            {codeError}
                        </span>
                    ) : null}
                </label>
                <ActionButton
                    disabled={code.length !== 6 || loading !== null}
                    label={
                        loading === 'confirm' ? copy.confirming : copy.confirm
                    }
                    loading={loading === 'confirm'}
                    onClick={onConfirm}
                />
            </div>
        </section>
    );
}

function ConfirmedState({
    cancelLabel,
    confirmingRegeneration,
    copy,
    loading,
    onConfirmRegeneration,
    onCancelRegeneration,
    onHideCodes,
    onRequestRegeneration,
    onShowCodes,
    recoveryCodes,
}: {
    cancelLabel: string;
    confirmingRegeneration: boolean;
    copy: MfaCopy;
    loading: Operation;
    onConfirmRegeneration: () => void;
    onCancelRegeneration: () => void;
    onHideCodes: () => void;
    onRequestRegeneration: () => void;
    onShowCodes: () => void;
    recoveryCodes: string[] | null;
}) {
    return (
        <section
            aria-live="polite"
            className="space-y-7 border-y border-[var(--arabut-line)] py-7"
        >
            <div className="flex items-start gap-4">
                <StateIcon icon={CheckCircle2} />
                <div className="space-y-2">
                    <h2 className="text-xl font-bold">{copy.configured}</h2>
                    <p className="text-base leading-7 text-[var(--arabut-muted)]">
                        {copy.configuredDescription}
                    </p>
                </div>
            </div>

            {recoveryCodes === null ? (
                <ActionButton
                    disabled={loading !== null}
                    label={copy.showRecoveryCodes}
                    loading={loading === 'recovery'}
                    onClick={onShowCodes}
                    variant="secondary"
                />
            ) : (
                <div className="space-y-5 border-t border-[var(--arabut-line)] pt-6">
                    <div className="max-w-xl space-y-2">
                        <h3 className="text-lg font-bold">
                            {copy.recoveryTitle}
                        </h3>
                        <p className="text-base leading-7 text-[var(--arabut-muted)]">
                            {copy.recoveryWarning}
                        </p>
                    </div>
                    <ul
                        aria-label={copy.recoveryTitle}
                        className="grid max-w-xl gap-2 sm:grid-cols-2"
                        dir="ltr"
                    >
                        {recoveryCodes.map((recoveryCode) => (
                            <li
                                className="border border-[var(--arabut-line)] bg-[var(--arabut-navy-raised)] px-3 py-2 text-center text-sm tracking-wide break-all"
                                key={recoveryCode}
                            >
                                <code>{recoveryCode}</code>
                            </li>
                        ))}
                    </ul>
                    <div className="flex flex-col gap-3 sm:flex-row sm:flex-wrap">
                        <ActionButton
                            disabled={loading !== null}
                            label={copy.hideRecoveryCodes}
                            onClick={onHideCodes}
                            variant="secondary"
                        />
                        <ActionButton
                            disabled={loading !== null}
                            label={copy.regenerateRecoveryCodes}
                            onClick={onRequestRegeneration}
                            variant="quiet"
                        />
                    </div>
                </div>
            )}

            {confirmingRegeneration ? (
                <section
                    aria-labelledby="regenerate-recovery-title"
                    className="space-y-4 border-t border-[var(--arabut-line)] pt-6"
                >
                    <div className="flex items-start gap-3">
                        <RotateCcw
                            aria-hidden="true"
                            className="mt-1 size-5 shrink-0 text-[var(--arabut-gold)]"
                        />
                        <div className="space-y-2">
                            <h3
                                className="text-lg font-bold"
                                id="regenerate-recovery-title"
                            >
                                {copy.regenerateTitle}
                            </h3>
                            <p className="text-base leading-7 text-[var(--arabut-muted)]">
                                {copy.regenerateDescription}
                            </p>
                        </div>
                    </div>
                    <div className="flex flex-col gap-3 sm:flex-row">
                        <ActionButton
                            disabled={loading !== null}
                            label={
                                loading === 'regenerate'
                                    ? copy.regenerating
                                    : copy.confirmRegenerate
                            }
                            loading={loading === 'regenerate'}
                            onClick={onConfirmRegeneration}
                        />
                        <ActionButton
                            disabled={loading !== null}
                            label={cancelLabel}
                            onClick={onCancelRegeneration}
                            variant="secondary"
                        />
                    </div>
                </section>
            ) : null}
        </section>
    );
}

function PasswordSetup({
    action,
    description,
    title,
    url,
}: {
    action: string;
    description: string;
    title: string;
    url: string;
}) {
    return (
        <section className="grid gap-6 border-y border-[var(--arabut-line)] py-7 sm:grid-cols-[auto_1fr] sm:items-start">
            <StateIcon icon={KeyRound} />
            <div className="space-y-5">
                <div className="space-y-2">
                    <h2 className="text-xl font-bold">{title}</h2>
                    <p className="text-base leading-7 text-[var(--arabut-muted)]">
                        {description}
                    </p>
                </div>
                <a
                    className="inline-flex min-h-11 touch-manipulation items-center justify-center rounded-md bg-[var(--arabut-gold-bright)] px-5 py-2 text-sm font-bold text-[var(--arabut-navy-deep)] transition-colors hover:bg-[#efc76a] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--arabut-focus)] active:brightness-95"
                    href={url}
                >
                    {action}
                </a>
            </div>
        </section>
    );
}

function StateIcon({ icon: Icon }: { icon: typeof KeyRound }) {
    return (
        <span className="flex size-11 shrink-0 items-center justify-center rounded-full border border-[var(--arabut-line)] bg-[var(--arabut-navy-raised)] text-[var(--arabut-gold-bright)]">
            <Icon aria-hidden="true" className="size-5" strokeWidth={1.8} />
        </span>
    );
}

function ActionButton({
    disabled = false,
    label,
    loading = false,
    onClick,
    variant = 'primary',
}: {
    disabled?: boolean;
    label: string;
    loading?: boolean;
    onClick: () => void;
    variant?: 'primary' | 'secondary' | 'quiet';
}) {
    const variants = {
        primary:
            'bg-[var(--arabut-gold-bright)] text-[var(--arabut-navy-deep)] hover:bg-[#efc76a]',
        secondary:
            'border border-[var(--arabut-line)] bg-[var(--arabut-navy-raised)] text-[var(--arabut-ink)] hover:border-[var(--arabut-gold)]',
        quiet: 'text-[var(--arabut-gold-bright)] hover:bg-[var(--arabut-navy-raised)]',
    };

    return (
        <button
            className={`inline-flex min-h-11 touch-manipulation items-center justify-center gap-2 rounded-md px-5 py-2 text-sm font-bold transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--arabut-focus)] active:brightness-95 disabled:cursor-not-allowed disabled:opacity-50 disabled:active:brightness-100 ${variants[variant]}`}
            disabled={disabled || loading}
            onClick={onClick}
            type="button"
        >
            {loading ? (
                <LoaderCircle
                    aria-hidden="true"
                    className="size-4 animate-spin motion-reduce:animate-none"
                />
            ) : null}
            {label}
        </button>
    );
}
