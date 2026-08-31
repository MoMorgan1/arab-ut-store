import type {
    StoreShellConfig,
    StoreShellTranslations,
} from '@/types/store-shell';

export type User = {
    id: number;
    first_name: string;
    last_name: string;
    name: string;
    email: string | null;
    avatar?: string;
    email_verified_at: string | null;
    created_at: string;
    updated_at: string;
    [key: string]: unknown;
};

export type Auth = {
    user: User;
};

export type AuthPage =
    | 'login'
    | 'register'
    | 'forgot_password'
    | 'reset_password'
    | 'confirm_password'
    | 'two_factor_challenge'
    | 'verify_email';

export type AuthRoutes = {
    homeUrl: string;
    loginUrl: string;
    loginStoreUrl: string;
    registerUrl: string;
    registerStoreUrl: string;
    forgotPasswordUrl: string;
    forgotPasswordStoreUrl: string;
    resetPasswordStoreUrl: string;
    googleLoginUrl: string | null;
    whatsappSendUrl: string;
    whatsappVerifyUrl: string;
};

export type AuthUiTranslations = {
    brand: string;
    fields: {
        first_name: string;
        last_name: string;
        email: string;
        password: string;
        password_confirmation: string;
        remember: string;
    };
    password_visibility: { show: string; hide: string };
    login: {
        head_title: string;
        title: string;
        description: string;
        submit: string;
        forgot_password: string;
        registration_prompt: string;
        registration_link: string;
        email_tab: string;
        tab_email: string;
        tab_email_short: string;
        phone_tab: string;
        country_code: string;
        phone_number: string;
        phone_account_hint: string;
        phone_send_code: string;
        phone_code: string;
        phone_verify: string;
        phone_code_sent: string;
        phone_code_sent_to: string;
        phone_code_invalid: string;
        phone_invalid: string;
        phone_unavailable: string;
        phone_change: string;
        phone_resend_in: string;
        phone_resend: string;
        phone_help: string;
        phone_help_support: string;
        google: string;
        google_error: string;
        or: string;
        terms_prefix: string;
        terms_link: string;
        terms_and: string;
        privacy_link: string;
    };
    register: {
        head_title: string;
        title: string;
        description: string;
        submit: string;
        login_prompt: string;
        login_link: string;
        password_requirements: {
            title: string;
            minimum: string;
            mixed_case: string;
            number: string;
            symbol: string;
        };
        phone_unavailable: string;
    };
    forgot_password: {
        head_title: string;
        title: string;
        description: string;
        submit: string;
        return_prompt: string;
        return_link: string;
    };
    reset_password: {
        head_title: string;
        title: string;
        description: string;
        submit: string;
    };
    confirm_password: {
        head_title: string;
        title: string;
        description: string;
        submit: string;
    };
    two_factor_challenge: {
        head_title: string;
        title: string;
        description: string;
        code: string;
        recovery_code: string;
        use_recovery_code: string;
        use_authenticator_code: string;
        invalid_code: string;
        invalid_recovery_code: string;
        submit: string;
    };
    verify_email: {
        head_title: string;
        title: string;
        description: string;
        submit: string;
        resend_in: string;
        sent: string;
        login_prompt: string;
        login_link: string;
    };
};

export type AuthSharedProps = {
    authPage: AuthPage;
    authRoutes: AuthRoutes;
    authUi: AuthUiTranslations;
    direction: 'rtl' | 'ltr';
    cartCount: number;
    displayCurrency: string;
    displayCurrencies: string[];
    locale: 'ar' | 'en';
    storeShell: StoreShellConfig;
    ui: StoreShellTranslations;
};

/* @chisel-passkeys */
export type Passkey = {
    id: number;
    name: string;
    authenticator: string | null;
    created_at_diff: string;
    last_used_at_diff: string | null;
};
/* @end-chisel-passkeys */
