import { Form, Head, Link, router, usePage } from '@inertiajs/react';

import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import type { AuthRoutes, AuthUiTranslations } from '@/types/auth';

type Props = {
    authRoutes: AuthRoutes;
    authUi: AuthUiTranslations;
    maskedEmail: string;
};

/**
 * The way in for an account the Salla import left without a password.
 *
 * It says why, in one sentence, because a customer who has just been told
 * their password is wrong needs to know it never existed rather than that they
 * forgot it.
 */
export default function LoginCode({ authRoutes, authUi, maskedEmail }: Props) {
    const copy = authUi.login;
    const { props } = usePage<{ status?: string }>();

    return (
        <>
            <Head title={copy.email_code_title} />

            <div className="space-y-6">
                <div className="space-y-2">
                    <h1 className="text-xl font-semibold">
                        {copy.email_code_title}
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        {copy.email_code_intro.replace(':email', maskedEmail)}
                    </p>
                </div>

                {props.status ? (
                    <p className="text-sm font-medium text-emerald-600 dark:text-emerald-400">
                        {props.status}
                    </p>
                ) : null}

                <Form
                    action={authRoutes.loginCodeStoreUrl}
                    method="post"
                    className="auth-form"
                >
                    {({ processing, errors }) => (
                        <div className="space-y-6">
                            <div className="grid gap-2">
                                <Label htmlFor="code">{copy.email_code}</Label>
                                <Input
                                    id="code"
                                    name="code"
                                    type="text"
                                    inputMode="numeric"
                                    autoComplete="one-time-code"
                                    maxLength={6}
                                    autoFocus
                                    required
                                    dir="ltr"
                                    className="h-11 text-center tracking-[0.5em]"
                                    aria-describedby={
                                        errors.code ? 'code-error' : undefined
                                    }
                                    aria-invalid={Boolean(errors.code)}
                                />
                                <InputError
                                    id="code-error"
                                    message={errors.code}
                                    role="alert"
                                />
                            </div>

                            <Button
                                type="submit"
                                className="auth-form__submit h-11 w-full"
                                disabled={processing}
                            >
                                {processing && <Spinner />}
                                {copy.email_code_verify}
                            </Button>
                        </div>
                    )}
                </Form>

                <div className="space-y-2 text-sm text-muted-foreground">
                    <p>
                        {copy.email_code_help}{' '}
                        <Button
                            type="button"
                            variant="link"
                            className="auth-inline-link min-h-11 px-0"
                            onClick={() =>
                                router.post(authRoutes.loginCodeResendUrl)
                            }
                        >
                            {copy.email_code_resend}
                        </Button>
                    </p>
                    <p>
                        <Link
                            href={authRoutes.loginUrl}
                            className="auth-inline-link"
                        >
                            {copy.email_code_back}
                        </Link>
                    </p>
                </div>
            </div>
        </>
    );
}
