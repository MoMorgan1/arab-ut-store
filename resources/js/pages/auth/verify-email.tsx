import { Head, router, usePage } from '@inertiajs/react';
import { useState } from 'react';

import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { useResendCountdown } from '@/hooks/use-resend-countdown';
import type { AuthSharedProps, AuthUiTranslations } from '@/types/auth';

type Props = {
    authUi: AuthUiTranslations;
    status?: string;
};

export default function VerifyEmail({ authUi, status }: Props) {
    const page = usePage<Partial<AuthSharedProps>>();
    const locale = page.props?.locale ?? 'ar';
    const copy = authUi.verify_email;
    const flashStatus = page.props.status ?? status;
    const whatsappUrl = page.props?.storeShell?.whatsappUrl;
    const [isSending, setIsSending] = useState(false);
    const countdown = useResendCountdown(60);

    function resend() {
        setIsSending(true);
        router.post(
            locale === 'en' ? '/en/verify-email/send' : '/verify-email/send',
            {},
            {
                preserveScroll: true,
                onSuccess: () => countdown.start(60),
                onFinish: () => setIsSending(false),
            },
        );
    }

    return (
        <>
            <Head title={copy.head_title} />

            {flashStatus === 'verification-link-sent' ? (
                <div
                    className="auth-form__status"
                    role="status"
                    aria-live="polite"
                >
                    {copy.sent}
                </div>
            ) : null}

            <Button
                className="auth-form__submit h-10 w-full"
                data-test="resend-verification-email-button"
                disabled={isSending}
                onClick={resend}
                type="button"
            >
                {isSending && <Spinner />}
                {copy.submit}
            </Button>

            {countdown.isActive ? (
                <p
                    role="status"
                    className="text-center text-sm text-muted-foreground"
                >
                    {copy.resend_in.replace(
                        ':seconds',
                        String(countdown.countdown),
                    )}
                </p>
            ) : null}

            <div className="auth-form__switch">
                {copy.login_prompt}{' '}
                {whatsappUrl ? (
                    <a
                        href={whatsappUrl}
                        rel="noopener noreferrer"
                        target="_blank"
                        className="auth-inline-link"
                    >
                        {copy.login_link}
                    </a>
                ) : (
                    copy.login_link
                )}
            </div>
        </>
    );
}
