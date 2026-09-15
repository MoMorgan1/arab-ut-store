import { cleanup, render } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';

import { CatalogCard } from '@/pages/store/category';
import type {
    CatalogProduct,
    StoreCategoryPageProps,
} from '@/types/store-content';

const page = vi.hoisted(() => ({
    props: {
        cartVariantIds: [],
        storeShell: { cartUrl: '/en/cart' },
    },
    url: '/en/store',
}));

vi.mock('@inertiajs/react', () => ({
    usePage: () => page,
}));

const mockProduct: CatalogProduct = {
    compareAtPrice: null,
    description: '100,000 Coins',
    id: 'prod-1',
    image: null,
    name: 'FC Coins 100k',
    platforms: ['playstation'],
    price: { amountMinor: 5000, currency: 'SAR' },
    promotionBadge: null,
    slug: 'product-1',
    url: '/en/products/product-1',
    variants: [
        {
            compareAtPrice: null,
            completionTiers: [],
            id: 'var-1',
            name: 'PlayStation',
            platform: 'playstation',
            price: { amountMinor: 5000, currency: 'SAR' },
            promotionBadge: null,
        },
    ],
};

const mockTranslations: StoreCategoryPageProps['catalogPage'] = {
    add_error: 'Could not add this item.',
    add_to_cart: 'Add to cart',
    added: 'Added to cart',
    adding: 'Adding…',
    all: 'All',
    assurance_fast: 'Fast delivery',
    assurance_fast_detail: 'Fast delivery of your challenge rewards.',
    assurance_no_players: 'your club is safe',
    assurance_no_players_detail:
        'We fund and complete the SBC without taking players.',
    assurance_secure: 'Secure service',
    assurance_secure_detail: 'Your account details stay protected.',
    assurance_support: '24/7 support',
    assurance_support_detail: 'Our team is available whenever you need it.',
    assurances: 'Store assurances',
    browse_by_type: 'Browse by type',
    empty: 'No services',
    filter: 'Filter',
    foundations: 'Foundations',
    from: 'From',
    icons: 'Icons',
    in_cart: 'In cart',
    included: 'Coins + completion',
    loading: 'Loading',
    newest: 'Newest',
    next: 'Next',
    open_cart: 'Open cart',
    page_status: 'Page :current of :total',
    pagination: 'Catalog pages',
    platform: 'Platform',
    platform_prices: 'Platform prices',
    players: 'Players',
    previous: 'Previous',
    price_asc: 'Price: low to high',
    price_desc: 'Price: high to low',
    recommended: 'Recommended',
    search: 'Search services',
    sort: 'Sort',
    unavailable_price: 'Price temporarily unavailable',
    upgrades: 'Upgrades',
};

const defaultProps = {
    addUrl: '/en/cart/items/catalog',
    isSbc: false,
    locale: 'en' as const,
    product: mockProduct,
    translations: mockTranslations,
};

afterEach(() => {
    cleanup();
});

describe('CatalogCard skeleton reveal', () => {
    it('renders data-revealing="true" and the --reveal-index custom property when asked', () => {
        const { container } = render(
            <CatalogCard {...defaultProps} revealIndex={3} revealing={true} />,
        );
        const card = container.querySelector('li');

        expect(card).toHaveClass('t-reveal-item');
        expect(card).toHaveAttribute('data-revealing', 'true');
        expect(card?.style.getPropertyValue('--reveal-index')).toBe('3');
    });

    it('renders neither data-revealing nor --reveal-index when not revealing', () => {
        const { container } = render(
            <CatalogCard {...defaultProps} revealIndex={3} revealing={false} />,
        );
        const card = container.querySelector('li');

        expect(card).toHaveClass('t-reveal-item');
        expect(card).not.toHaveAttribute('data-revealing');
        expect(card?.style.getPropertyValue('--reveal-index')).toBe('');
    });

    it('caps the reveal index at 7', () => {
        const { container } = render(
            <CatalogCard {...defaultProps} revealIndex={15} revealing={true} />,
        );
        const card = container.querySelector('li');

        expect(card).toHaveAttribute('data-revealing', 'true');
        expect(card?.style.getPropertyValue('--reveal-index')).toBe('7');
    });
});
