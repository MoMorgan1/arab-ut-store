import { cleanup, render, screen } from '@testing-library/react';
import { afterEach, expect, it, vi } from 'vitest';

import SimpleStorePage from '@/pages/store/simple-page';

const mockPage = vi.hoisted(() => ({
    props: {
        cartCount: 7,
        direction: 'ltr',
        displayCurrency: 'SAR',
        displayCurrencies: ['SAR', 'USD'],
        locale: 'en',
        page: {
            body: 'We are preparing the SBC catalog and automated product connection.',
            key: 'sbc',
            title: 'SBC Services',
        },
        storeShell: {
            homeUrl: '/en',
            coinsUrl: '/en#coins',
            cartUrl: '/en/cart',
            sbcUrl: '/en/sbc',
            futChampionsUrl: '/en/fut-champions',
            accountUrl: '/en/my-account',
            privacyUrl: '/en/privacy',
            returnsUrl: '/en/returns',
            warrantyUrl: '/en/warranty',
            eaBackupCodesUrl: '/en/ea-backup-codes',
            termsUrl: '/en/terms',
            whatsappUrl: 'https://wa.me/966537998099',
            email: 'support@example.com',
            socials: { x: '', instagram: '' },
            payments: [],
        },
        ui: {
            brand: 'Arab UT',
            currency_selector: 'Choose display currency',
            language: 'العربية',
            simple_pages: {
                back_home: 'Back to home',
                eyebrow: 'Arab UT',
            },
            skip_to_content: 'Skip to content',
            store_tools: 'Store tools',
            header: {
                primary_navigation: 'Primary navigation',
                preferences: 'Display preferences',
                home: 'Home',
                coins: 'Coins',
                sbc: 'SBC',
                fut_champions: 'FUT Champions',
                most_requested: 'Most requested',
                whatsapp: 'WhatsApp',
                cart: 'Cart',
                account: 'Account',
            },
            footer: {
                description: 'Trusted FC 27 services.',
                important_links: 'Important links',
                privacy: 'Privacy Policy',
                returns: 'Returns Policy',
                warranty: 'Warranty and Compensation',
                ea_backup_codes: 'EA Backup Codes',
                terms: 'Terms of Service',
                customer_service: 'Customer service',
                whatsapp: 'WhatsApp support',
                payment_methods: 'Payment methods at launch',
                copyright: 'Copyright © :year Arab UT.',
                ea_disclaimer: 'Independent from EA Sports.',
                exchange_rate_attribution: 'Rates By Exchange Rate API',
            },
        },
    },
    url: '/en/sbc',
}));

vi.mock('@inertiajs/react', () => ({
    Head: ({ title }: { title: string }) => <title>{title}</title>,
    usePage: () => mockPage,
}));

afterEach(cleanup);

it('renders a non-transactional branded destination', () => {
    render(<SimpleStorePage />);

    expect(screen.getByRole('heading', { name: 'SBC Services' })).toBeVisible();
    expect(
        screen.getByText(
            'We are preparing the SBC catalog and automated product connection.',
        ),
    ).toBeVisible();
    expect(screen.getByRole('banner')).toBeVisible();
    expect(
        screen.getByRole('link', { name: 'Cart' }).querySelector('span'),
    ).toHaveTextContent('7');
    expect(screen.queryByRole('form')).not.toBeInTheDocument();
    expect(
        screen.queryByRole('button', { name: /pay|checkout/i }),
    ).not.toBeInTheDocument();
    expect(document.querySelector('a[href="#"]')).not.toBeInTheDocument();
});

it('keeps the complete shell semantic order, active nav, and safe external links', () => {
    render(<SimpleStorePage />);

    const banner = screen.getByRole('banner');
    const main = screen.getByRole('main');
    const contentinfo = screen.getByRole('contentinfo');

    expect([...document.body.querySelectorAll('header, main, footer')]).toEqual(
        [banner, main, contentinfo],
    );
    expect(screen.getByRole('link', { name: /^SBC/ })).toHaveAttribute(
        'aria-current',
        'page',
    );

    for (const link of document.querySelectorAll<HTMLAnchorElement>('a')) {
        const href = link.getAttribute('href');

        expect(href).not.toBe('#');

        if (href?.startsWith('http')) {
            expect(link).toHaveAttribute('target', '_blank');
            expect(link).toHaveAttribute('rel', 'noopener noreferrer');
        }
    }
});
