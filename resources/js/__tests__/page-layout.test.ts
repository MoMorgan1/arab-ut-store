import { describe, expect, it } from 'vitest';

import { usesAuthLayout } from '@/lib/page-layout';

describe('usesAuthLayout', () => {
    it.each(['account/overview', 'account/orders', 'account/profile'])(
        'keeps %s outside the legacy application shell',
        (page) => {
            expect(usesAuthLayout(page)).toBe(false);
        },
    );

    it('keeps store pages outside the legacy shell', () => {
        expect(usesAuthLayout('store/home')).toBe(false);
    });

    it('uses the dedicated authentication shell for auth pages', () => {
        expect(usesAuthLayout('auth/login')).toBe(true);
    });
});
