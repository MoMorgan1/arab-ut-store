import type { StoreLocale } from '@/types/store-shell';

export type WhatsAppContext =
    | 'coins'
    | 'sbc'
    | 'fut_champions'
    | 'cart'
    | 'terms'
    | 'warranty'
    | 'returns'
    | 'privacy'
    | 'general';

const CONTEXT_MESSAGES: Record<WhatsAppContext, Record<StoreLocale, string>> = {
    coins: {
        ar: 'مرحباً متجر عرب التيميت، لدي استفسار بخصوص شحن كوينز FC 27.',
        en: 'Hello Arab UT Store, I have an inquiry about FC 27 Coins delivery.',
    },
    sbc: {
        ar: 'مرحباً متجر عرب التيميت، لدي استفسار حول حلول وتحديات SBC.',
        en: 'Hello Arab UT Store, I have an inquiry regarding SBC challenge services.',
    },
    fut_champions: {
        ar: 'مرحباً متجر عرب التيميت، أود الاستفسار عن خدمة فوت تشامبيونز.',
        en: 'Hello Arab UT Store, I would like to ask about the FUT Champions service.',
    },
    cart: {
        ar: 'مرحباً متجر عرب التيميت، أحتاج إلى مساعدة في إتمام طلبي وسلتي.',
        en: 'Hello Arab UT Store, I need assistance completing my cart checkout.',
    },
    terms: {
        ar: 'مرحباً متجر عرب التيميت، لدي استفسار بخصوص الشروط والأحكام.',
        en: 'Hello Arab UT Store, I have a question about the Terms and Conditions.',
    },
    warranty: {
        ar: 'مرحباً متجر عرب التيميت، لدي استفسار حول سياسة الضمان والتعويض.',
        en: 'Hello Arab UT Store, I have a question about the Warranty and Compensation policy.',
    },
    returns: {
        ar: 'مرحباً متجر عرب التيميت، لدي استفسار بخصوص سياسة الاسترجاع.',
        en: 'Hello Arab UT Store, I have a question regarding the Returns Policy.',
    },
    privacy: {
        ar: 'مرحباً متجر عرب التيميت، لدي استفسار بخصوص سياسة الخصوصية.',
        en: 'Hello Arab UT Store, I have a question regarding the Privacy Policy.',
    },
    general: {
        ar: 'مرحباً متجر عرب التيميت، أحتاج إلى مساعدة من خدمة العملاء.',
        en: 'Hello Arab UT Store, I need assistance from customer service.',
    },
};

export function buildContextualWhatsAppUrl(
    baseUrl: string,
    locale: StoreLocale = 'ar',
    context: WhatsAppContext = 'general',
): string {
    if (!baseUrl) {
        return 'https://wa.me/966537998099';
    }

    const message =
        CONTEXT_MESSAGES[context]?.[locale] || CONTEXT_MESSAGES.general[locale];
    const encoded = encodeURIComponent(message);
    const separator = baseUrl.includes('?') ? '&' : '?';

    return `${baseUrl}${separator}text=${encoded}`;
}
