import { describe, expect, it } from 'vitest';

import { isUtcWireTimestamp, isWireUlid } from '@/lib/wire-validators';

describe('Coins wire validators', () => {
    it.each([
        ['01K00000000000000000000000', true],
        ['71K00000000000000000000000', true],
        ['81K00000000000000000000000', false],
        ['01K0000000000000000000000I', false],
        ['not-a-ulid', false],
        [null, false],
    ])('validates ULID wire value %s consistently', (value, expected) => {
        expect(isWireUlid(value)).toBe(expected);
    });

    it.each([
        ['2026-08-10T12:00:00Z', true],
        ['2026-08-10T12:00:00.123Z', true],
        ['2026-08-10T12:00:00+00:00', true],
        ['2026-08-10T15:00:00+03:00', false],
        ['2026-08-10T12:00:00+00', false],
        ['2026-02-30T12:00:00Z', false],
        [null, false],
    ])(
        'validates UTC timestamp wire value %s consistently',
        (value, expected) => {
            expect(isUtcWireTimestamp(value)).toBe(expected);
        },
    );
});
