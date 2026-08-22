import { describe, expect, it } from 'vitest';
import { chatTopicsFor } from '@/lib/chat-topics';

describe('chatTopicsFor', () => {
    it('returns four Arabic topics by default', () => {
        const topics = chatTopicsFor('ar');

        expect(topics.map((topic) => topic.label)).toEqual([
            'الأسعار',
            'الخدمات',
            'متابعة الطلب',
            'الدعم',
        ]);
        expect(topics.map((topic) => topic.id)).toEqual([
            'prices',
            'services',
            'track-order',
            'support',
        ]);
    });

    it('returns English topics for en and falls back to Arabic otherwise', () => {
        expect(chatTopicsFor('en')[2].label).toBe('Track Order');
        expect(chatTopicsFor('fr')[0].label).toBe('الأسعار');
    });
});
