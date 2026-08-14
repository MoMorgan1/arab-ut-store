import type {
    StoreShellConfig,
    StoreShellTranslations,
} from '@/types/store-shell';

export type User = {
    id: number;
    first_name: string;
    last_name: string;
    name: string;
    email: string;
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
    | 'confirm_password';

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
    benefits: {
        eyebrow: string;
        title: string;
        description: string;
        items: [string, string, string];
    };
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
        phone_tab: string;
        country_code: string;
        phone_number: string;
        phone_account_hint: string;
        phone_send_code: string;
        phone_code: string;
        phone_verify: string;
        phone_code_sent: string;
        phone_code_invalid: string;
        phone_invalid: string;
        phone_unavailable: string;
        phone_change: string;
        google: string;
        google_error: string;
        or: string;
    };
    register: {
        head_title: string;
        title: string;
        description: string;
        submit: string;
        login_prompt: string;
        login_link: string;
        password_symbol_error: string;
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
