import { cleanup, render, screen } from '@testing-library/react';
import { afterEach, expect, it } from 'vitest';

import { ManualServiceSuggestions } from '@/components/configurator/manual-services/manual-service-suggestions';
import type { ManualServiceCommonTranslations } from '@/types/manual-services';

afterEach(cleanup);

const mockCommon: ManualServiceCommonTranslations = {
    step_platform: 'Platform',
    step_options: 'Service details',
    step_account: 'Account details',
    step_image: 'Squad image',
    panel_title: 'Your order',
    eta_label: 'Delivery time',
    squad_image_choose: 'Choose image',
    back: 'Back to services',
    platform_legend: 'Choose platform',
    platforms: {
        playstation: 'PlayStation',
        pc: 'PC',
    },
    platform_captions: {
        playstation: 'PS4 and PS5',
        pc: 'EA app or Steam',
    },
    pc_store_legend: 'Choose game launcher',
    pc_stores: {
        ea_app: 'EA app',
        steam: 'Steam',
    },
    account_details_title: 'Account details',
    ea_email: 'EA email',
    ea_password: 'EA password',
    steam_username: 'Steam username',
    steam_password: 'Steam password',
    playstation_email: 'PlayStation email',
    playstation_password: 'PlayStation password',
    show_password: 'Show password',
    hide_password: 'Hide password',
    ea_codes: '3 EA backup codes',
    ea_codes_help: 'Each code must be eight digits.',
    playstation_codes: '3 PlayStation backup codes',
    playstation_codes_help: 'Each code must be six characters.',
    backup_code: 'Backup code :number',
    squad_image: 'Squad image',
    squad_image_help: 'Upload one image.',
    squad_image_remove: 'Remove image',
    ea_tutorial: 'EA backup code guide',
    playstation_tutorial: 'PlayStation backup code guide',
    notes_title: 'Important notes',
    add_to_cart: 'Add to cart',
    adding: 'Adding…',
    added: 'Added',
    add_error: 'Could not add service.',
    unavailable_title: 'Unavailable',
    unavailable_body: 'Pricing is being updated.',
    review_title: 'Review',
    review_service: 'Service',
    review_platform: 'Platform',
    review_launcher: 'Launcher',
    review_total: 'Total',
    review_credentials: 'Credentials',
    review_credentials_ready: 'Credentials ready',
    review_image_ready: 'Image ready',
    required_field: 'Required',
    invalid_email: 'Invalid email',
    invalid_ea_code: 'Invalid EA code',
    invalid_playstation_code: 'Invalid PS code',
    duplicate_codes: 'Duplicate code',
    image_required: 'Image required',
    image_invalid: 'Image invalid',
    image_too_large: 'Image too large',
    see_all_sbc: 'All SBC challenges',
};

const mockTranslations = {
    eyebrow: 'More services',
    title: 'Continue with Arab UT',
    open: 'Open service',
};

it('renders the SBC product rail with links, prices, and the other service card', () => {
    render(
        <ManualServiceSuggestions
            common={mockCommon}
            locale="en"
            relatedServices={{
                products: [
                    {
                        id: 'sbc-1',
                        name: 'Player Moments SBC',
                        description: 'Complete the player moments challenge.',
                        url: '/en/sbc/player-moments',
                        image: {
                            url: '/images/store/sbc/player-moments.webp',
                            alt: 'Player Moments',
                        },
                        price: { amountMinor: 15000, currency: 'SAR' },
                        compareAtPrice: { amountMinor: 20000, currency: 'SAR' },
                        promotionBadge: '25% OFF',
                        platforms: ['playstation', 'pc'],
                    },
                    {
                        id: 'sbc-2',
                        name: 'Icon SBC',
                        description: 'Unlock an elite icon for your club.',
                        url: '/en/sbc/icon-challenge',
                        image: null,
                        price: { amountMinor: 35000, currency: 'SAR' },
                        compareAtPrice: null,
                        promotionBadge: null,
                        platforms: ['playstation'],
                    },
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

    // Check SBC products
    const sbc1Link = screen.getByRole('link', { name: /Player Moments SBC/i });
    expect(sbc1Link).toHaveAttribute('href', '/en/sbc/player-moments');
    expect(sbc1Link).toHaveTextContent('SAR 150.00');
    expect(sbc1Link).toHaveTextContent('SAR 200.00');
    expect(sbc1Link).toHaveTextContent('25% OFF');

    const sbc2Link = screen.getByRole('link', { name: /Icon SBC/i });
    expect(sbc2Link).toHaveAttribute('href', '/en/sbc/icon-challenge');
    expect(sbc2Link).toHaveTextContent('SAR 350.00');

    // Check "See all" link
    expect(
        screen.getByRole('link', { name: /All SBC challenges/i }),
    ).toHaveAttribute('href', '/en/sbc');

    // Check other manual service card
    expect(
        screen.getByRole('link', { name: /Division Rivals/i }),
    ).toHaveAttribute('href', '/en/rivals');
});

it('renders gracefully when SBC products are empty', () => {
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
        screen.getByRole('heading', { name: 'Continue with Arab UT' }),
    ).toBeVisible();
    expect(
        screen.queryByRole('link', { name: /All SBC challenges/i }),
    ).not.toBeInTheDocument();
    expect(
        screen.getByRole('link', { name: /FUT Champions/i }),
    ).toHaveAttribute('href', '/en/fut-champions');
});
