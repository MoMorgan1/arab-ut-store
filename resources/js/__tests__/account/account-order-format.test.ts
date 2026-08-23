import { describe, expect, it } from 'vitest';

import { formatOrderDate, formatOrderNumber } from '@/lib/account-order-format';

describe('account order formatting helpers', () => {
    it('shortens UT prefixed order numbers with more than 8 characters to last 6 digits with hash', () => {
        expect(formatOrderNumber('UT-00012345')).toBe('#012345');
        expect(formatOrderNumber('UT-10000001')).toBe('#000001');
    });

    it('passes non-UT order numbers and short numbers through untouched', () => {
        expect(formatOrderNumber('ORD-999999')).toBe('ORD-999999');
        expect(formatOrderNumber('12345678')).toBe('12345678');
        expect(formatOrderNumber('UT-1234')).toBe('UT-1234');
    });

    it('formats dates appropriately for Arabic and English locales', () => {
        const isoDate = '2026-08-15T10:00:00.000Z';
        const arDate = formatOrderDate(isoDate, 'ar');
        const enDate = formatOrderDate(isoDate, 'en');

        expect(typeof arDate).toBe('string');
        expect(arDate.length).toBeGreaterThan(0);
        expect(typeof enDate).toBe('string');
        expect(enDate.length).toBeGreaterThan(0);
        expect(enDate).toContain('2026');
    });
});
