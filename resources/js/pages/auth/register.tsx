import { Form, Head } from '@inertiajs/react';
import InputError from '@/components/input-error';
import PasswordInput from '@/components/password-input';
import TextLink from '@/components/text-link';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import type { AuthRoutes, AuthUiTranslations } from '@/types/auth';

type Props = {
    authRoutes: AuthRoutes;
    authUi: AuthUiTranslations;
    passwordRules: string;
};

export default function Register({ authRoutes, authUi, passwordRules }: Props) {
    return (
        <>
            <Head title={authUi.register.head_title} />
            <Form
                action={authRoutes.registerStoreUrl}
                method="post"
                resetOnSuccess={['password', 'password_confirmation']}
                disableWhileProcessing
                className="auth-form"
            >
                {({ processing, errors }) => (
                    <>
                        <div className="grid gap-6">
                            <div className="grid gap-2 sm:grid-cols-2">
                                <div className="grid gap-2">
                                    <Label htmlFor="first_name">
                                        {authUi.fields.first_name}
                                    </Label>
                                    <Input
                                        id="first_name"
                                        type="text"
                                        required
                                        autoFocus
                                        tabIndex={1}
                                        autoComplete="given-name"
                                        name="first_name"
                                        placeholder={authUi.fields.first_name}
                                        className="h-11"
                                    />
                                    <InputError
                                        message={errors.first_name}
                                        className="mt-2"
                                    />
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="last_name">
                                        {authUi.fields.last_name}
                                    </Label>
                                    <Input
                                        id="last_name"
                                        type="text"
                                        required
                                        tabIndex={2}
                                        autoComplete="family-name"
                                        name="last_name"
                                        placeholder={authUi.fields.last_name}
                                        className="h-11"
                                    />
                                    <InputError
                                        message={errors.last_name}
                                        className="mt-2"
                                    />
                                </div>
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="email">
                                    {authUi.fields.email}
                                </Label>
                                <Input
                                    id="email"
                                    type="email"
                                    required
                                    tabIndex={3}
                                    autoComplete="email"
                                    name="email"
                                    placeholder="email@example.com"
                                    className="h-11"
                                />
                                <InputError message={errors.email} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="password">
                                    {authUi.fields.password}
                                </Label>
                                <PasswordInput
                                    id="password"
                                    required
                                    tabIndex={4}
                                    autoComplete="new-password"
                                    name="password"
                                    placeholder={authUi.fields.password}
                                    passwordrules={passwordRules}
                                    className="h-11"
                                    showLabel={authUi.password_visibility.show}
                                    hideLabel={authUi.password_visibility.hide}
                                />
                                <InputError message={errors.password} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="password_confirmation">
                                    {authUi.fields.password_confirmation}
                                </Label>
                                <PasswordInput
                                    id="password_confirmation"
                                    required
                                    tabIndex={5}
                                    autoComplete="new-password"
                                    name="password_confirmation"
                                    placeholder={
                                        authUi.fields.password_confirmation
                                    }
                                    passwordrules={passwordRules}
                                    className="h-11"
                                    showLabel={authUi.password_visibility.show}
                                    hideLabel={authUi.password_visibility.hide}
                                />
                                <InputError
                                    message={errors.password_confirmation}
                                />
                            </div>

                            <Button
                                type="submit"
                                className="mt-2 h-11 w-full"
                                tabIndex={6}
                                data-test="register-user-button"
                            >
                                {processing && <Spinner />}
                                {authUi.register.submit}
                            </Button>
                        </div>

                        <div className="auth-form__switch">
                            {authUi.register.login_prompt}{' '}
                            <TextLink
                                className="auth-inline-link"
                                href={authRoutes.loginUrl}
                                tabIndex={7}
                            >
                                {authUi.register.login_link}
                            </TextLink>
                        </div>
                    </>
                )}
            </Form>
        </>
    );
}
