import {
    cleanup,
    fireEvent,
    render,
    screen,
    waitFor,
} from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';

import { CatalogAddControl } from '@/components/store/catalog/catalog-add-control';
import { SbcProductConfigurator } from '@/components/store/catalog/sbc-product-configurator';
import type { ManualServiceCommonTranslations } from '@/types/manual-services';
import type {
    CatalogProduct,
    ProductTranslations,
} from '@/types/store-content';

const page = vi.hoisted(() => ({
    props: {} as Record<string, unknown>,
    url: '/en/sbc/icon-challenge',
}));

vi.mock('@inertiajs/react', () => ({
    Head: ({ title }: { title: string }) => <title>{title}</title>,
    usePage: () => page,
}));

afterEach(() => {
    cleanup();
    document.head.innerHTML = '';
    vi.unstubAllGlobals();
    vi.restoreAllMocks();
});

const manualCommon = {
    step_platform: 'Platform',
    platform_legend: 'Choose platform',
    platforms: { playstation: 'PlayStation', pc: 'PC' },
    panel_title: 'Your order',
    review_total: 'Total',
    review_credentials_ready: 'Credentials are sent securely.',
    add_to_cart: 'Add service to cart',
    adding: 'Adding…',
    added: 'Service added to cart',
    add_error: 'Could not add this item.',
    in_cart: 'In cart',
    open_cart: 'Open cart',
    backup_code: 'Backup code :number',
    ea_tutorial: 'EA backup code guide',
} as unknown as ManualServiceCommonTranslations;

const product: CatalogProduct = {
    compareAtPrice: null,
    description: 'We fund and complete this SBC.',
    id: '01K00000000000000000000003',
    image: null,
    name: 'Icon Challenge',
    platforms: ['playstation'],
    price: { amountMinor: 12500, currency: 'SAR' },
    promotionBadge: null,
    slug: 'icon-challenge',
    url: '/en/sbc/icon-challenge',
    variants: [
        {
            compareAtPrice: null,
            completionTiers: [
                {
                    completions: 5,
                    price: { amountMinor: 12500, currency: 'SAR' },
                },
            ],
            id: '01K00000000000000000000003',
            name: 'PS / Xbox',
            platform: 'playstation',
            price: { amountMinor: 12500, currency: 'SAR' },
            promotionBadge: null,
        },
    ],
};

const translations = {
    add_to_cart: 'Add to cart',
    adding: 'Adding…',
    sbc: {
        platform_legend: 'Choose platform',
        completion_legend: 'Number of completions',
        completion_option: ':count completions',
        completion_summary: 'Completions',
        credentials_title: 'EA account details',
        email: 'EA email',
        password: 'EA password',
        show_password: 'Show password',
        hide_password: 'Hide password',
        backup_codes: 'EA backup codes',
        backup_help: 'Enter three different eight-digit codes.',
        backup_code: 'Backup code :number',
        required_email: 'Enter a valid EA email.',
        required_password: 'Enter your EA password.',
        required_code: 'Enter an eight-digit code.',
        duplicate_code: 'Use a different code.',
        selected: 'platform',
        total: 'Total',
        success: 'Added securely',
        credentials_ready: 'Your account details travel safely.',
    },
} as unknown as ProductTranslations;

function renderSbc(variantIds: string[]) {
    page.props = {
        cartVariantIds: variantIds,
        storeShell: { cartUrl: '/en/cart' },
    };

    return render(
        <SbcProductConfigurator
            addUrl="/en/cart/items/sbc"
            currentUrl="/en/sbc/icon-challenge"
            direction="ltr"
            locale="en"
            manualCommon={manualCommon}
            product={product}
            translations={translations}
            tutorials={{ ea: 'https://help.ea.com/backup-codes' }}
        />,
    );
}

describe('SBC in-cart state', () => {
    it('renders the add control when the variant is not in the cart', () => {
        renderSbc([]);

        expect(
            screen.getByRole('button', { name: 'Add to cart' }),
        ).toBeEnabled();
        expect(
            screen.queryByRole('link', { name: 'Open cart' }),
        ).not.toBeInTheDocument();
    });

    it('renders the in-cart state when the variant is in cartVariantIds', () => {
        renderSbc(['01K00000000000000000000003']);

        const inCart = screen.getByRole('button', { name: 'In cart' });

        expect(inCart).toHaveAttribute('data-state', 'in-cart');
        expect(screen.getByRole('link', { name: 'Open cart' })).toHaveAttribute(
            'href',
            '/en/cart',
        );
    });
});

describe('CatalogAddControl duplicate', () => {
    function renderControl() {
        page.props = {
            cartVariantIds: [],
            storeShell: { cartUrl: '/en/cart' },
        };

        return render(
            <CatalogAddControl
                addUrl="/en/cart/items/catalog"
                amountMinor={9900}
                currency="SAR"
                errorLabel="Could not add this item."
                idleLabel="Add to cart"
                inCartLabel="In cart"
                loadingLabel="Adding…"
                imageAlt="Icon Challenge"
                imageUrl="/images/icon-challenge.webp"
                itemLabel="Icon Challenge"
                locale="en"
                openCartLabel="Open cart"
                selectionLabel="PS / Xbox"
                variantId="01K00000000000000000000003"
            />,
        );
    }

    it('shows the duplicate sheet and the in-cart state on a 409', async () => {
        document.head.innerHTML = '<meta name="csrf-token" content="csrf">';
        vi.stubGlobal(
            'fetch',
            vi.fn(() =>
                Promise.resolve(
                    new Response(
                        JSON.stringify({
                            error: {
                                code: 'already_in_cart',
                                message: 'Already in cart.',
                                cartUrl: '/en/cart',
                            },
                        }),
                        { status: 409 },
                    ),
                ),
            ),
        );

        const seen: string[] = [];
        const listener = (event: Event) => {
            seen.push(
                (event as CustomEvent<{ variant?: string }>).detail.variant ??
                    '',
            );
        };
        window.addEventListener('arabut:cart-added', listener);

        renderControl();
        fireEvent.click(screen.getByRole('button', { name: 'Add to cart' }));

        await waitFor(() => {
            expect(
                screen.getByRole('button', { name: 'In cart' }),
            ).toBeInTheDocument();
        });

        expect(seen).toEqual(['duplicate']);
        expect(screen.getByRole('link', { name: 'Open cart' })).toHaveAttribute(
            'href',
            '/en/cart',
        );
        expect(screen.queryByRole('alert')).not.toBeInTheDocument();
        window.removeEventListener('arabut:cart-added', listener);
    });
});
