import { describe, expect, it } from 'vitest';

import {
    formatAdminMoneyFromHalalah,
    formatHalalahToSar,
    parseSarToHalalah,
} from '@/components/admin/admin-money';

describe('admin-money helpers', () => {
    describe('parseSarToHalalah', () => {
        it('correctly converts 0 to 0 halalah', () => {
            expect(parseSarToHalalah('0')).toBe(0);
            expect(parseSarToHalalah('0.00')).toBe(0);
            expect(parseSarToHalalah(0)).toBe(0);
        });

        it('correctly converts 0.05 to 5 halalah', () => {
            expect(parseSarToHalalah('0.05')).toBe(5);
            expect(parseSarToHalalah(0.05)).toBe(5);
        });

        it('correctly converts 19.99 to 1999 halalah', () => {
            expect(parseSarToHalalah('19.99')).toBe(1999);
            expect(parseSarToHalalah(19.99)).toBe(1999);
        });

        it('correctly converts 120 to 12000 halalah', () => {
            expect(parseSarToHalalah('120')).toBe(12000);
            expect(parseSarToHalalah('120.0')).toBe(12000);
            expect(parseSarToHalalah('120.00')).toBe(12000);
            expect(parseSarToHalalah(120)).toBe(12000);
        });

        it('correctly converts 120.5 to 12050 halalah', () => {
            expect(parseSarToHalalah('120.5')).toBe(12050);
            expect(parseSarToHalalah('120.50')).toBe(12050);
            expect(parseSarToHalalah(120.5)).toBe(12050);
        });

        it('correctly converts 1234.56 to 123456 halalah', () => {
            expect(parseSarToHalalah('1234.56')).toBe(123456);
            expect(parseSarToHalalah(1234.56)).toBe(123456);
        });

        it('truncates values with > 2 decimal places to exactly 2 digits', () => {
            expect(parseSarToHalalah('120.555')).toBe(12055);
            expect(parseSarToHalalah('0.129')).toBe(12);
            expect(parseSarToHalalah('19.9999')).toBe(1999);
        });

        it('handles negative values', () => {
            expect(parseSarToHalalah('-19.99')).toBe(-1999);
            expect(parseSarToHalalah('-0.05')).toBe(-5);
        });

        it('handles null, undefined, and empty string', () => {
            expect(parseSarToHalalah(null)).toBe(0);
            expect(parseSarToHalalah(undefined)).toBe(0);
            expect(parseSarToHalalah('')).toBe(0);
            expect(parseSarToHalalah('   ')).toBe(0);
        });
    });

    describe('formatHalalahToSar', () => {
        it('formats 0 halalah to 0.00', () => {
            expect(formatHalalahToSar(0)).toBe('0.00');
            expect(formatHalalahToSar('0')).toBe('0.00');
            expect(formatHalalahToSar(0n)).toBe('0.00');
        });

        it('formats 5 halalah to 0.05', () => {
            expect(formatHalalahToSar(5)).toBe('0.05');
            expect(formatHalalahToSar('5')).toBe('0.05');
        });

        it('formats 1999 halalah to 19.99', () => {
            expect(formatHalalahToSar(1999)).toBe('19.99');
            expect(formatHalalahToSar('1999')).toBe('19.99');
        });

        it('formats 12000 halalah to 120.00', () => {
            expect(formatHalalahToSar(12000)).toBe('120.00');
            expect(formatHalalahToSar('12000')).toBe('120.00');
        });

        it('formats 12050 halalah to 120.50', () => {
            expect(formatHalalahToSar(12050)).toBe('120.50');
            expect(formatHalalahToSar('12050')).toBe('120.50');
        });

        it('formats 123456 halalah to 1234.56', () => {
            expect(formatHalalahToSar(123456)).toBe('1234.56');
            expect(formatHalalahToSar('123456')).toBe('1234.56');
        });

        it('handles negative halalah', () => {
            expect(formatHalalahToSar(-1999)).toBe('-19.99');
            expect(formatHalalahToSar(-5)).toBe('-0.05');
        });

        it('handles null, undefined, and empty string', () => {
            expect(formatHalalahToSar(null)).toBe('0.00');
            expect(formatHalalahToSar(undefined)).toBe('0.00');
            expect(formatHalalahToSar('')).toBe('0.00');
        });
    });

    describe('formatAdminMoneyFromHalalah', () => {
        it('formats halalah integer to localized currency string', () => {
            const enFormatted = formatAdminMoneyFromHalalah(12000, 'en', 'SAR');
            expect(enFormatted).toContain('120.00');
            expect(enFormatted).toContain('SAR');

            const arFormatted = formatAdminMoneyFromHalalah(12000, 'ar', 'SAR');
            expect(arFormatted).toBeDefined();
        });
    });
});
