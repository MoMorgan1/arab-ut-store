import type {
    StoreLocale,
    StoreShellConfig,
    StoreShellTranslations,
} from '@/types/store-shell';

export type ManualServicePlatform = 'playstation' | 'pc';
export type PcLauncher = 'ea_app' | 'steam';
export type Division = '7' | '6' | '5' | '4' | '3' | '2' | '1' | 'elite';
export type ManualServiceMoney = { amountMinor: number; currency: string };

export type ManualServiceSuggestion = {
    key: 'sbc' | 'fut_champions' | 'rivals';
    title: string;
    description: string;
    href: string;
    imageUrl: string;
};

export type ManualServiceSuggestionTranslations = {
    eyebrow: string;
    title: string;
    open: string;
};

export type ManualCredentialsDraft = {
    eaEmail: string;
    eaPassword: string;
    eaCodes: [string, string, string];
    playstationEmail: string;
    playstationPassword: string;
    playstationCodes: [string, string, string];
    steamUsername: string;
    steamPassword: string;
};

export type ManualServiceCommonTranslations = {
    back: string;
    platform_legend: string;
    platforms: Record<ManualServicePlatform, string>;
    pc_store_legend: string;
    pc_stores: Record<PcLauncher, string>;
    account_details_title: string;
    ea_email: string;
    ea_password: string;
    steam_username: string;
    steam_password: string;
    playstation_email: string;
    playstation_password: string;
    show_password: string;
    hide_password: string;
    ea_codes: string;
    ea_codes_help: string;
    playstation_codes: string;
    playstation_codes_help: string;
    backup_code: string;
    squad_image: string;
    squad_image_help: string;
    squad_image_remove: string;
    ea_tutorial: string;
    playstation_tutorial: string;
    notes_title: string;
    add_to_cart: string;
    adding: string;
    added: string;
    add_error: string;
    unavailable_title: string;
    unavailable_body: string;
    review_title: string;
    review_service: string;
    review_platform: string;
    review_launcher: string;
    review_total: string;
    review_credentials: string;
    review_credentials_ready: string;
    review_image_ready: string;
    required_field: string;
    invalid_email: string;
    invalid_ea_code: string;
    invalid_playstation_code: string;
    duplicate_codes: string;
    image_required: string;
    image_invalid: string;
    image_too_large: string;
};

export type FutServiceTranslations = {
    eyebrow: string;
    title: string;
    intro: string;
    target_legend: string;
    rank: string;
    urgent: string;
    urgent_price: string;
    urgent_eta: string;
    standard_eta: string;
    matches_question: string;
    matches_yes: string;
    matches_no: string;
    matches_played: string;
    notes: Record<
        'timing' | 'details' | 'login' | 'shortfall' | 'safety',
        string
    >;
};

export type RivalsServiceTranslations = {
    eyebrow: string;
    title: string;
    intro: string;
    current_legend: string;
    target_legend: string;
    division: string;
    elite: string;
    standard_eta: string;
    notes: Record<'timing' | 'login' | 'shortfall' | 'safety', string>;
};

export type ManualServicePageProps = {
    backUrl: string;
    cartCount: number;
    direction: 'rtl' | 'ltr';
    displayCurrencies: string[];
    displayCurrency: string;
    locale: StoreLocale;
    storeShell: StoreShellConfig;
    ui: StoreShellTranslations;
    manualServicePage: {
        common: ManualServiceCommonTranslations;
        relatedServices: ManualServiceSuggestion[];
        relatedTranslations: ManualServiceSuggestionTranslations;
        service: FutServiceTranslations | RivalsServiceTranslations;
    };
    manualService: {
        active: boolean;
        addUrl: string;
        service: 'fut_champions' | 'rivals';
        scheduleVersion: number | null;
        platforms: ManualServicePlatform[];
        tutorials: { ea: string; playstation: string };
        product: {
            id: string | null;
            slug: string;
            name: string;
            description: string;
            image: { alt: string; url: string };
        };
        pricing:
            | {
                  currency: string;
                  rankOptions: Array<{
                      rank: number;
                      price: ManualServiceMoney;
                  }>;
                  urgentSurcharge: ManualServiceMoney;
              }
            | {
                  currency: string;
                  ladder: Division[];
                  stepOptions: Array<{
                      from: Division;
                      to: Division;
                      price: ManualServiceMoney;
                  }>;
              }
            | null;
    };
};

export const emptyManualCredentials = (): ManualCredentialsDraft => ({
    eaEmail: '',
    eaPassword: '',
    eaCodes: ['', '', ''],
    playstationEmail: '',
    playstationPassword: '',
    playstationCodes: ['', '', ''],
    steamUsername: '',
    steamPassword: '',
});
