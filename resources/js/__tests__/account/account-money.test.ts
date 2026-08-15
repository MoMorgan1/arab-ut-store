import { expect, it } from 'vitest';

import { formatAccountMoney } from '@/lib/account-money';

it('formats exact large minor-unit strings without number coercion', () => {
    expect(
        formatAccountMoney(
            { amountMinor: '9223372036854775807', currency: 'SAR' },
            'en',
        ),
    ).toContain('92,233,720,368,547,758.07');
});

it('keeps the exact fraction and Arabic currency label in RTL output', () => {
    const formatted = formatAccountMoney(
        { amountMinor: '12999', currency: 'SAR' },
        'ar',
    );

    expect(formatted).toContain('129.99');
    expect(formatted).toContain('ر.س');
});
