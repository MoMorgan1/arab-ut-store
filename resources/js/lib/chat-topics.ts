export type ChatTopicId = 'prices' | 'services' | 'track-order' | 'support';

export type ChatTopic = {
    id: ChatTopicId;
    label: string;
};

const TOPIC_IDS: ChatTopicId[] = [
    'prices',
    'services',
    'track-order',
    'support',
];

const LABELS: Record<'ar' | 'en', string[]> = {
    ar: ['الأسعار', 'الخدمات', 'متابعة الطلب', 'الدعم'],
    en: ['Prices', 'Services', 'Track Order', 'Support'],
};

export function chatTopicsFor(locale: string | undefined): ChatTopic[] {
    const labels = locale === 'en' ? LABELS.en : LABELS.ar;

    return TOPIC_IDS.map((id, index) => ({ id, label: labels[index] }));
}
