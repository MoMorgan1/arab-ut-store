import { afterEach, describe, expect, it, vi } from 'vitest';

import { newAttemptKey } from '@/lib/attempt-key';

afterEach(() => {
    vi.unstubAllGlobals();
});

describe('newAttemptKey', () => {
    it('uses crypto.randomUUID when available', () => {
        vi.stubGlobal('crypto', {
            randomUUID: () => 'uuid-1234-5678',
        });

        expect(newAttemptKey()).toBe('uuid-1234-5678');
        expect(newAttemptKey('custom')).toBe('uuid-1234-5678');
    });

    it('falls back to prefixed timestamp string when crypto is undefined or randomUUID is missing', () => {
        vi.stubGlobal('crypto', undefined);

        const key1 = newAttemptKey('checkout');
        expect(key1).toMatch(/^checkout-\d+-[a-z0-9]+$/);

        const key2 = newAttemptKey();
        expect(key2).toMatch(/^attempt-\d+-[a-z0-9]+$/);
    });
});
