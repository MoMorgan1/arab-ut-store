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

    // Dates render in English on the Gregorian calendar in both interfaces.
    // An iPhone previously showed "١٠ ربيع الأول" because `ar-SA` carries the
    // Umm al-Qura calendar as its regional default and Safari honours it, while
    // Node's ICU falls back to Gregorian — so the old assertion, which only
    // checked the string was non-empty, passed throughout.
    it('formats order dates in English on the Gregorian calendar', () => {
        const formatted = formatOrderDate('2026-08-15T10:00:00.000Z');

        expect(formatted).toContain('2026');
        expect(formatted).toContain('Aug');
        // No Arabic-Indic digits and no Hijri month names.
        expect(formatted).not.toMatch(/[٠-٩۰-۹]/);
        expect(formatted).not.toMatch(/ربيع|محرم|رمضان|شوال/);
    });
});
