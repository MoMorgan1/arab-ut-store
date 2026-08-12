import { cleanup, render, screen, within } from '@testing-library/react';
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
            blocks: [
                {
                    content: [
                        { text: 'Arab UT respects your ' },
                        { strong: true, text: 'privacy' },
                        { text: '.' },
                    ],
                    type: 'paragraph',
                },
                {
                    level: 2,
                    text: '1. Information We Collect',
                    type: 'heading',
                },
                {
                    items: [
                        [{ text: 'Contact information.' }],
                        [{ text: 'Order details.' }],
                    ],
                    ordered: false,
                    type: 'list',
                },
                {
                    content: [{ text: 'Keep account details secure.' }],
                    tone: 'info',
                    type: 'notice',
                },
                {
                    content: [
                        { text: 'Read the ' },
                        {
                            text: 'official guide',
                            url: 'https://help.ea.com/en/articles/security-and-rules/two-factor-authentication/',
                        },
                        { text: '.' },
                    ],
                    type: 'paragraph',
                },
            ],
            breadcrumb: {
                current: 'Privacy Policy',
                home: 'Home',
                label: 'Breadcrumb',
            },
            key: 'privacy',
            support: {
                action: 'Contact us on WhatsApp',
                subtitle: 'Our team is ready to help around the clock',
                title: 'Have a question?',
                url: 'https://wa.me/966537998099',
            },
            title: 'Privacy Policy',
            updated: { label: 'Last updated', value: '12 August 2026' },
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
            preferences: {
                exchange_rate_attribution: 'Rates By Exchange Rate API',
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
            },
        },
    },
    url: '/en/privacy',
}));

vi.mock('@inertiajs/react', () => ({
    Head: ({ title }: { title: string }) => <title>{title}</title>,
    usePage: () => mockPage,
}));

afterEach(cleanup);

it('renders the structured WordPress policy hierarchy without transactional controls', () => {
    render(<SimpleStorePage />);

    expect(
        screen.getByRole('heading', { name: 'Privacy Policy' }),
    ).toBeVisible();
    expect(
        screen.getByRole('heading', { name: '1. Information We Collect' }),
    ).toBeVisible();
    expect(screen.getByText('privacy').tagName).toBe('STRONG');
    const prose = document.querySelector('.store-info-page__prose');

    expect(prose).not.toBeNull();
    expect(within(prose as HTMLElement).getByRole('list')).toHaveTextContent(
        'Order details.',
    );
    expect(screen.getByRole('note')).toHaveTextContent(
        'Keep account details secure.',
    );
    expect(
        screen.getByRole('navigation', { name: 'Breadcrumb' }),
    ).toHaveTextContent('Home');
    expect(screen.getByText('Last updated:')).toBeVisible();
    expect(screen.getByText('12 August 2026')).toBeVisible();
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

it('keeps the complete shell semantic order and safe external support links', () => {
    render(<SimpleStorePage />);

    const banner = screen.getByRole('banner');
    const main = screen.getByRole('main');
    const contentinfo = screen.getByRole('contentinfo');

    expect([...document.body.querySelectorAll('header, main, footer')]).toEqual(
        [banner, main, contentinfo],
    );
    expect(
        screen.getByRole('link', { name: 'Contact us on WhatsApp' }),
    ).toHaveAttribute('href', 'https://wa.me/966537998099');
    expect(
        screen.getByRole('link', { name: 'official guide' }),
    ).toHaveAttribute(
        'href',
        'https://help.ea.com/en/articles/security-and-rules/two-factor-authentication/',
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
