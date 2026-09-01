import { cleanup, render, screen } from '@testing-library/react';
import { afterEach, expect, it } from 'vitest';

import { ManualServiceSuggestions } from '@/components/configurator/manual-services/manual-service-suggestions';
import type {
    ManualServiceCommonTranslations,
    ManualServiceSuggestionTranslations,
} from '@/types/manual-services';
import type { CatalogProduct } from '@/types/store-content';

afterEach(cleanup);

const mockCommon = {
    see_all_sbc: 'All SBC challenges',
    platforms: { playstation: 'PlayStation', pc: 'PC' },
} as unknown as ManualServiceCommonTranslations;

const mockTranslations: ManualServiceSuggestionTranslations = {
    eyebrow: 'More services',
    title: 'Continue with Arab UT',
    open: 'Open service',
    sbc: {
        included: 'Coins and completion included',
        platform_prices: 'Platform prices',
        unavailable_price: 'Price unavailable',
    },
};

function sbcProduct(
    id: string,
    name: string,
    priceMinor: number,
): CatalogProduct {
    return {
        id,
        slug: id,
        url: `/en/sbc/${id}`,
        name,
        description: `${name} description`,
        image: null,
        price: { amountMinor: priceMinor, currency: 'SAR' },
        compareAtPrice: null,
        promotionBadge: null,
        platforms: ['playstation'],
        variants: [
            {
                id: `${id}-ps`,
                name: 'PlayStation',
                platform: 'playstation',
                price: { amountMinor: priceMinor, currency: 'SAR' },
                compareAtPrice: null,
                promotionBadge: null,
                completionTiers: [],
            },
        ],
    };
}

it('renders the SBC catalog cards the product page uses, a see-all link, and the other service card', () => {
    render(
        <ManualServiceSuggestions
            common={mockCommon}
            locale="en"
            relatedServices={{
                products: [
                    sbcProduct('player-moments', 'Player Moments SBC', 15000),
                    sbcProduct('icon-challenge', 'Icon SBC', 35000),
                ],
                sbcUrl: '/en/sbc',
                service: {
                    key: 'rivals',
                    title: 'Division Rivals',
                    description: 'Move up to your target division.',
                    href: '/en/rivals',
                    imageUrl: '/images/store/services/rivals.webp',
                },
            }}
            translations={mockTranslations}
        />,
    );

    expect(
        screen.getByRole('heading', { name: 'Continue with Arab UT' }),
    ).toBeVisible();

    const firstCard = screen.getByRole('link', { name: 'Player Moments SBC' });
    expect(firstCard).toHaveAttribute('href', '/en/sbc/player-moments');
    expect(firstCard).toHaveTextContent('SAR 150.00');
    expect(firstCard).toHaveTextContent('Coins and completion included');
    expect(screen.getByRole('link', { name: 'Icon SBC' })).toHaveTextContent(
        'SAR 350.00',
    );
    expect(
        screen.getByRole('link', { name: /All SBC challenges/ }),
    ).toHaveAttribute('href', '/en/sbc');
    expect(
        screen.getByRole('link', { name: /Division Rivals/ }),
    ).toHaveAttribute('href', '/en/rivals');
});

it('keeps the other service card when no SBC product is public', () => {
    render(
        <ManualServiceSuggestions
            common={mockCommon}
            locale="en"
            relatedServices={{
                products: [],
                sbcUrl: '/en/sbc',
                service: {
                    key: 'fut_champions',
                    title: 'FUT Champions',
                    description: 'Get your best rank with our team.',
                    href: '/en/fut-champions',
                    imageUrl: '/images/store/services/fut-champions.webp',
                },
            }}
            translations={mockTranslations}
        />,
    );

    expect(
        screen.queryByRole('link', { name: /All SBC challenges/ }),
    ).not.toBeInTheDocument();
    expect(screen.getByRole('link', { name: /FUT Champions/ })).toHaveAttribute(
        'href',
        '/en/fut-champions',
    );
});
