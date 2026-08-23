import type { CoinsDeliveryValue, CoinsPlatformValue } from '@/types/coins';

export type ChatCartCopy = {
    added: string;
    addFailed: string;
    adding: string;
    addToCart: string;
    backupCode: (position: number) => string;
    backupCodes: string;
    companion: string;
    confirmAdd: string;
    credentialsNotice: string;
    currentBalance: string;
    deliveries: Record<CoinsDeliveryValue, string>;
    eaEmail: string;
    eaPassword: string;
    fixFields: string;
    platforms: Record<CoinsPlatformValue, string>;
    policy: string;
    quantity: (formatted: string) => string;
    viewCart: string;
};

/**
 * Copy for the in-chat cart panel.
 *
 * The chat widget renders its own strings rather than reading page props: it
 * lives on every storefront route, and none of them carry the coin
 * configurator's translations.
 */
export function chatCartCopy(locale: 'ar' | 'en'): ChatCartCopy {
    if (locale === 'en') {
        return {
            added: 'Added to your cart',
            addFailed: 'That did not go through. Try again in a moment.',
            adding: 'Adding…',
            addToCart: 'Add to cart',
            backupCode: (position) => `Backup code ${position}`,
            backupCodes: 'Backup codes',
            companion:
                'The Web App / Companion market is open on this account.',
            confirmAdd: 'Add to cart',
            credentialsNotice:
                'Your EA details go straight to your cart, encrypted. They are never posted in this chat.',
            currentBalance: 'Current coin balance',
            deliveries: { fast: 'Fast', normal: 'Normal' },
            eaEmail: 'EA email',
            eaPassword: 'EA password',
            fixFields: 'Check the highlighted fields.',
            platforms: { pc: 'PC', playstation: 'PS / Xbox' },
            policy: 'I accept the terms and the delivery policy.',
            quantity: (formatted) => `${formatted} coins`,
            viewCart: 'View cart',
        };
    }

    return {
        added: 'انضاف لسلتك',
        addFailed: 'ما تمت الإضافة. جرب مرة ثانية بعد شوي.',
        adding: 'جاري الإضافة…',
        addToCart: 'أضف للسلة',
        backupCode: (position) => `رمز احتياطي ${position}`,
        backupCodes: 'الرموز الاحتياطية',
        companion: 'سوق الويب أب / الكومبانيون مفتوح على هذا الحساب.',
        confirmAdd: 'أضف للسلة',
        credentialsNotice:
            'بيانات حسابك تروح مباشرة للسلة مشفّرة، وما تنكتب في المحادثة.',
        currentBalance: 'رصيد الكوينز الحالي',
        deliveries: { fast: 'سريع', normal: 'عادي' },
        eaEmail: 'إيميل EA',
        eaPassword: 'كلمة مرور EA',
        fixFields: 'راجع الحقول المعلّمة.',
        platforms: { pc: 'PC', playstation: 'PS / Xbox' },
        policy: 'أوافق على الشروط وسياسة التسليم.',
        quantity: (formatted) => `${formatted} كوينز`,
        viewCart: 'افتح السلة',
    };
}
