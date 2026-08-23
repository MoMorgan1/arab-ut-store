'use no memo';

import { router } from '@inertiajs/react';
import {
    AlertTriangle,
    CheckCircle2,
    KeyRound,
    LoaderCircle,
    RotateCcw,
    ShieldCheck,
    ShieldOff,
} from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';

import {
    AdminMfaApiError,
    confirmAdminMfa,
    enableAdminMfa,
    forgetAdminMfaTrustedDevices,
    loadAdminMfaQrCode,
    loadAdminMfaRecoveryCodes,
    regenerateAdminMfaRecoveryCodes,
} from '@/lib/admin-mfa-api';
import type { AdminMfaState, AdminTranslations } from '@/types/admin';

type Operation =
    | 'enable'
    | 'confirm'
    | 'recovery'
    | 'regenerate'
    | 'forgetTrustedDevices'
    | null;
type RetryOperation =
    | 'loadQrCode'
    | 'startEnrollment'
    | 'confirmEnrollment'
    | 'showRecoveryCodes'
    | 'regenerateRecoveryCodes'
    | 'forgetTrustedDevices';
type FailureRecovery = 'retry' | 'login' | 'home' | 'reauthenticate' | 'wait';
type FailureState = { message: string; recovery: FailureRecovery };

export type AdminSecuritySectionProps = {
    adminUi: AdminTranslations;
    direction: 'rtl' | 'ltr';
    locale: 'ar' | 'en';
    mfa: AdminMfaState;
};

export default function AdminSecuritySection({
    adminUi,
    locale,
    mfa,
}: AdminSecuritySectionProps) {
    const copy = adminUi.mfa;
    const settingsCopy = adminUi.settings;
    const mounted = useRef(true);
    const retry = useRef<RetryOperation | null>(null);
    const [enabled, setEnabled] = useState(mfa.enabled);
    const [confirmed, setConfirmed] = useState(mfa.confirmed);
    const [code, setCode] = useState('');
    const [codeError, setCodeError] = useState<string | null>(null);
    const [qrSvg, setQrSvg] = useState<string | null>(null);
    const [recoveryCodes, setRecoveryCodes] = useState<string[] | null>(null);
    const [confirmingRegeneration, setConfirmingRegeneration] = useState(false);
    const [trustedDeviceCount, setTrustedDeviceCount] = useState(
        mfa.trustedDeviceCount,
    );
    const [confirmingForgetDevices, setConfirmingForgetDevices] =
        useState(false);
    const [operation, setOperation] = useState<Operation>(null);
    const [failure, setFailure] = useState<FailureState | null>(null);

    const clearSensitiveState = useCallback(() => {
        setCode('');
        setCodeError(null);
        setQrSvg(null);
        setRecoveryCodes(null);
        setConfirmingRegeneration(false);
        setConfirmingForgetDevices(false);
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
        if (mounted.current) {
            setRecoveryCodes(null);
            setConfirmingRegeneration(false);
        }

        const regenerated = await run(
            'regenerate',
            'regenerateRecoveryCodes',
            async () => {
                try {
                    await regenerateAdminMfaRecoveryCodes(
                        mfa.routes.regenerateRecoveryCodes,
                    );
                } catch (error) {
                    if (isAmbiguousRegenerationFailure(error)) {
                        retry.current = 'showRecoveryCodes';
                    }

                    throw error;
                }
            },
        );

        if (regenerated && mounted.current) {
            await showRecoveryCodes();
        }
    }, [mfa.routes.regenerateRecoveryCodes, run, showRecoveryCodes]);

    const forgetTrustedDevices = useCallback(async (): Promise<void> => {
        if (mounted.current) {
            setConfirmingForgetDevices(false);
        }

        await run('forgetTrustedDevices', 'forgetTrustedDevices', async () => {
            await forgetAdminMfaTrustedDevices(mfa.routes.forgetTrustedDevices);

            if (mounted.current) {
                setTrustedDeviceCount(0);
            }
        });
    }, [mfa.routes.forgetTrustedDevices, run]);

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
        <section
            aria-labelledby="admin-security-title"
            className="rounded-lg border border-border bg-card p-6 shadow-xs"
            id="security"
        >
            <div className="flex flex-col gap-6">
                <header className="flex flex-wrap items-start justify-between gap-4 border-b border-border pb-4">
                    <div className="space-y-1">
                        <div className="flex items-center gap-2">
                            <ShieldCheck
                                aria-hidden="true"
                                className="size-5 text-primary"
                            />
                            <h2
                                className="font-display text-xl font-bold tracking-tight text-foreground"
                                id="admin-security-title"
                            >
                                {settingsCopy.securitySection}
                            </h2>
                        </div>
                        <p className="text-sm text-muted-foreground">
                            {copy.description}
                        </p>
                    </div>
                </header>

                {failure ? (
                    <div
                        className="flex flex-col gap-4 rounded-md border border-destructive/50 bg-destructive/10 p-4 sm:flex-row sm:items-center sm:justify-between"
                        role="alert"
                    >
                        <p className="flex items-start gap-3 text-sm leading-6 font-medium text-destructive">
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
                                    retry.current === 'regenerateRecoveryCodes'
                                ) {
                                    void regenerateRecoveryCodes();
                                } else if (
                                    retry.current === 'forgetTrustedDevices'
                                ) {
                                    void forgetTrustedDevices();
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
                        confirmingForgetDevices={confirmingForgetDevices}
                        confirmingRegeneration={confirmingRegeneration}
                        copy={copy}
                        loading={operation}
                        onCancelForgetDevices={() =>
                            setConfirmingForgetDevices(false)
                        }
                        onCancelRegeneration={() =>
                            setConfirmingRegeneration(false)
                        }
                        onConfirmForgetDevices={() =>
                            void forgetTrustedDevices()
                        }
                        onConfirmRegeneration={() =>
                            void regenerateRecoveryCodes()
                        }
                        onHideCodes={() => setRecoveryCodes(null)}
                        onRequestForgetDevices={() =>
                            setConfirmingForgetDevices(true)
                        }
                        onRequestRegeneration={() =>
                            setConfirmingRegeneration(true)
                        }
                        onShowCodes={() => void showRecoveryCodes()}
                        recoveryCodes={recoveryCodes}
                        trustedDeviceCount={trustedDeviceCount}
                        trustedDeviceDays={mfa.trustedDeviceDays}
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
            </div>
        </section>
    );
}

type MfaCopy = AdminTranslations['mfa'];

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

function isAmbiguousRegenerationFailure(error: unknown): boolean {
    return (
        !(error instanceof AdminMfaApiError) ||
        ['network', 'server', 'invalid_response'].includes(error.code)
    );
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
    const settingsUrl = english ? '/en/admin/settings' : '/admin/settings';

    if (failure.recovery === 'wait') {
        return <FailureLink href={settingsUrl} label={copy.retryAfterWait} />;
    }

    const destinations = {
        home: english ? '/en' : '/',
        login: english ? '/en/login' : '/login',
        reauthenticate: settingsUrl,
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
            className="inline-flex min-h-11 touch-manipulation items-center justify-center rounded-md border border-border bg-card px-5 py-2 text-sm font-medium text-foreground transition-colors hover:bg-accent focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring"
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
        <section className="grid gap-6 py-4 sm:grid-cols-[auto_1fr] sm:items-start">
            <StateIcon icon={KeyRound} />
            <div className="space-y-5">
                <div className="max-w-xl space-y-2">
                    <h3 className="text-lg font-semibold text-foreground">
                        {copy.startTitle}
                    </h3>
                    <p className="text-sm leading-6 text-muted-foreground">
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
        <section className="grid gap-8 py-4 md:grid-cols-[12rem_1fr] md:items-start">
            <div className="flex aspect-square w-48 max-w-full items-center justify-center rounded-md border border-border bg-white p-3">
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
                        className="size-8 animate-spin text-primary motion-reduce:animate-none"
                    />
                )}
            </div>
            <div className="space-y-5">
                <div className="space-y-2">
                    <h3 className="text-lg font-semibold text-foreground">
                        {copy.scanTitle}
                    </h3>
                    <p className="text-sm leading-6 text-muted-foreground">
                        {copy.scanDescription}
                    </p>
                </div>
                <label className="grid max-w-sm gap-2" htmlFor="admin-mfa-code">
                    <span className="text-xs font-semibold text-foreground">
                        {copy.confirmCode}
                    </span>
                    <input
                        aria-describedby={
                            codeError ? 'admin-mfa-code-error' : undefined
                        }
                        aria-invalid={codeError ? true : undefined}
                        autoComplete="one-time-code"
                        className="min-h-11 rounded-md border border-input bg-transparent px-4 text-center text-lg tracking-[0.35em] tabular-nums transition-colors outline-none focus-visible:border-ring focus-visible:ring-2 focus-visible:ring-ring"
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
                            className="text-xs font-medium text-destructive"
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
    confirmingForgetDevices,
    confirmingRegeneration,
    copy,
    loading,
    onCancelForgetDevices,
    onCancelRegeneration,
    onConfirmForgetDevices,
    onConfirmRegeneration,
    onHideCodes,
    onRequestForgetDevices,
    onRequestRegeneration,
    onShowCodes,
    recoveryCodes,
    trustedDeviceCount,
    trustedDeviceDays,
}: {
    cancelLabel: string;
    confirmingForgetDevices: boolean;
    confirmingRegeneration: boolean;
    copy: MfaCopy;
    loading: Operation;
    onCancelForgetDevices: () => void;
    onCancelRegeneration: () => void;
    onConfirmForgetDevices: () => void;
    onConfirmRegeneration: () => void;
    onHideCodes: () => void;
    onRequestForgetDevices: () => void;
    onRequestRegeneration: () => void;
    onShowCodes: () => void;
    recoveryCodes: string[] | null;
    trustedDeviceCount: number;
    trustedDeviceDays: number;
}) {
    return (
        <section aria-live="polite" className="space-y-6 py-4">
            <div className="flex items-start gap-4">
                <StateIcon icon={CheckCircle2} />
                <div className="space-y-1">
                    <h3 className="text-lg font-semibold text-foreground">
                        {copy.configured}
                    </h3>
                    <p className="text-sm leading-6 text-muted-foreground">
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
                <div className="space-y-5 border-t border-border pt-6">
                    <div className="max-w-xl space-y-2">
                        <h4 className="text-base font-semibold text-foreground">
                            {copy.recoveryTitle}
                        </h4>
                        <p className="text-sm leading-6 text-muted-foreground">
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
                                className="rounded-md border border-border bg-muted/40 px-3 py-2 text-center font-mono text-sm tracking-wide break-all text-foreground"
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
                    className="space-y-4 border-t border-border pt-6"
                >
                    <div className="flex items-start gap-3">
                        <RotateCcw
                            aria-hidden="true"
                            className="mt-1 size-5 shrink-0 text-primary"
                        />
                        <div className="space-y-1">
                            <h4
                                className="text-base font-semibold text-foreground"
                                id="regenerate-recovery-title"
                            >
                                {copy.regenerateTitle}
                            </h4>
                            <p className="text-sm leading-6 text-muted-foreground">
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

            <div className="space-y-5 border-t border-border pt-6">
                <div className="max-w-xl space-y-2">
                    <h4 className="text-base font-semibold text-foreground">
                        {copy.trustedDevicesTitle}
                    </h4>
                    <p className="text-sm leading-6 text-muted-foreground">
                        {trustedDeviceCount > 0
                            ? copy.trustedDevicesDescription
                                  .replace(':count', String(trustedDeviceCount))
                                  .replace(':days', String(trustedDeviceDays))
                            : copy.trustedDevicesNone}
                    </p>
                </div>

                <div className="flex flex-col gap-3 sm:flex-row sm:flex-wrap">
                    <ActionButton
                        disabled={trustedDeviceCount === 0 || loading !== null}
                        label={copy.forgetTrustedDevices}
                        onClick={onRequestForgetDevices}
                        variant="secondary"
                    />
                </div>
            </div>

            {confirmingForgetDevices ? (
                <section
                    aria-labelledby="forget-trusted-devices-title"
                    className="space-y-4 border-t border-border pt-6"
                >
                    <div className="flex items-start gap-3">
                        <ShieldOff
                            aria-hidden="true"
                            className="mt-1 size-5 shrink-0 text-primary"
                        />
                        <div className="space-y-1">
                            <h4
                                className="text-base font-semibold text-foreground"
                                id="forget-trusted-devices-title"
                            >
                                {copy.forgetTrustedDevicesTitle}
                            </h4>
                            <p className="text-sm leading-6 text-muted-foreground">
                                {copy.forgetTrustedDevicesDescription}
                            </p>
                        </div>
                    </div>
                    <div className="flex flex-col gap-3 sm:flex-row">
                        <ActionButton
                            disabled={loading !== null}
                            label={
                                loading === 'forgetTrustedDevices'
                                    ? copy.forgettingTrustedDevices
                                    : copy.confirmForgetTrustedDevices
                            }
                            loading={loading === 'forgetTrustedDevices'}
                            onClick={onConfirmForgetDevices}
                        />
                        <ActionButton
                            disabled={loading !== null}
                            label={cancelLabel}
                            onClick={onCancelForgetDevices}
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
        <section className="grid gap-6 py-4 sm:grid-cols-[auto_1fr] sm:items-start">
            <StateIcon icon={KeyRound} />
            <div className="space-y-5">
                <div className="space-y-2">
                    <h3 className="text-lg font-semibold text-foreground">
                        {title}
                    </h3>
                    <p className="text-sm leading-6 text-muted-foreground">
                        {description}
                    </p>
                </div>
                <a
                    className="inline-flex min-h-11 touch-manipulation items-center justify-center rounded-md bg-primary px-5 py-2 text-sm font-semibold text-primary-foreground transition-colors hover:bg-primary/90 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring"
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
        <span className="flex size-11 shrink-0 items-center justify-center rounded-full border border-border bg-accent text-primary">
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
        primary: 'bg-primary text-primary-foreground hover:bg-primary/90',
        secondary:
            'border border-input bg-background text-foreground hover:bg-accent hover:text-accent-foreground',
        quiet: 'text-primary hover:bg-accent',
    };

    return (
        <button
            className={`inline-flex min-h-11 touch-manipulation items-center justify-center gap-2 rounded-md px-5 py-2 text-sm font-semibold transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring disabled:cursor-not-allowed disabled:opacity-50 ${variants[variant]}`}
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
