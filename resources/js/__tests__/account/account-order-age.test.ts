import { describe, expect, it } from 'vitest';

import { formatOrderAge } from '@/lib/account-order-format';

const now = new Date('2026-09-10T12:00:00Z');

describe('formatOrderAge', () => {
    it('reads as how long ago, in the customer language', () => {
        expect(formatOrderAge('2026-09-10T10:00:00Z', 'en', now)).toBe(
            '2 hours ago',
        );
        expect(formatOrderAge('2026-09-09T11:00:00Z', 'en', now)).toBe(
            'yesterday',
        );
        expect(formatOrderAge('2026-07-10T12:00:00Z', 'en', now)).toBe(
            '2 months ago',
        );
        expect(formatOrderAge('2026-09-10T10:00:00Z', 'ar', now)).toMatch(
            /ساعتين/,
        );
        expect(formatOrderAge('2026-07-10T12:00:00Z', 'ar', now)).toMatch(
            /شهرين/,
        );
    });

    it('never says a future order was placed later', () => {
        expect(formatOrderAge('2026-09-10T11:59:50Z', 'en', now)).toBe('now');
    });
});
