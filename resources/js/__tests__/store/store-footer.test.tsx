import { cleanup, render, screen, within } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';

import StoreLayout from '@/layouts/store-layout';
import type {
    StoreShellConfig,
    StoreShellTranslations,
} from '@/types/store-shell';

const shell: StoreShellConfig = {
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
    email: 'info@arab-ut.com',
    socials: {
        x: 'https://x.com/fut_fi',
        instagram: 'https://www.instagram.com/arabutcoins/',
    },
    payments: [
        {
            name: 'Mada',
            imageUrl: '/images/store/payments/mada.png',
            width: 120,
            height: 41,
        },
        {
            name: 'Visa',
            imageUrl: '/images/store/payments/visa.png',
            width: 120,
            height: 39,
        },
        {
            name: 'Mastercard',
            imageUrl: '/images/store/payments/mastercard.png',
            width: 120,
            height: 75,
        },
        {
            name: 'Apple Pay',
            imageUrl: '/images/store/payments/apple-pay.png',
            width: 120,
            height: 50,
        },
    ],
};

const translations = {
    brand: 'Arab UT',
    cart_added: {
        title: 'Added to your cart',
        message: ':item is ready in your cart.',
        buy_now: 'Buy now',
        continue_shopping: 'Continue shopping',
    },
    language: 'العربية',
    currency_selector: 'Display currency',
    home_title: 'Home',
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
        description: 'Trusted FC 27 services and secure Coins delivery.',
        important_links: 'Important links',
        privacy: 'Privacy Policy',
        returns: 'Returns Policy',
        warranty: 'Warranty and Compensation',
        ea_backup_codes: 'EA Backup Codes',
        terms: 'Terms of Service',
        customer_service: 'Customer service',
        whatsapp: 'WhatsApp support',
        payment_methods: 'Payment methods at launch',
        copyright: 'Copyright © :year Arab UT. All rights reserved.',
        ea_disclaimer:
            'All EA FC assets are the property of EA Sports. Arab UT is an independent service and is not affiliated with EA Sports or Electronic Arts Inc.',
    },
} satisfies StoreShellTranslations;

function renderFooter() {
    render(
        <StoreLayout
            cartCount={0}
            currentUrl="/en"
            direction="ltr"
            displayCurrencies={['SAR', 'USD']}
            displayCurrency="USD"
            locale="en"
            storeShell={shell}
            ui={translations}
        >
            <p>Store content</p>
        </StoreLayout>,
    );

    return screen.getByRole('contentinfo');
}

afterEach(() => {
    cleanup();
    vi.useRealTimers();
});

describe('StoreFooter', () => {
    it('renders the three-column WordPress hierarchy with real legal destinations', () => {
        const footer = renderFooter();
        const importantLinks = within(footer).getByRole('navigation', {
            name: 'Important links',
        });

        expect(
            within(footer)
                .getAllByRole('heading', { level: 2 })
                .map((heading) => heading.textContent),
        ).toEqual(['Arab UT', 'Important links', 'Customer service']);

        for (const [name, href] of [
            ['Privacy Policy', '/en/privacy'],
            ['Returns Policy', '/en/returns'],
            ['Warranty and Compensation', '/en/warranty'],
            ['EA Backup Codes', '/en/ea-backup-codes'],
            ['Terms of Service', '/en/terms'],
        ]) {
            expect(
                within(importantLinks).getByRole('link', { name }),
            ).toHaveAttribute('href', href);
        }

        expect(document.querySelectorAll('footer')).toHaveLength(1);
        expect(document.querySelector('a[href="#"]')).not.toBeInTheDocument();
    });

    it('links only the verified social and customer-service destinations', () => {
        const footer = renderFooter();

        const xLink = within(footer).getByRole('link', { name: 'X' });
        const instagramLink = within(footer).getByRole('link', {
            name: 'Instagram',
        });

        expect(xLink).toHaveAttribute('href', 'https://x.com/fut_fi');
        expect(instagramLink).toHaveAttribute(
            'href',
            'https://www.instagram.com/arabutcoins/',
        );

        for (const socialLink of [xLink, instagramLink]) {
            expect(socialLink).toHaveAttribute('target', '_blank');
            expect(socialLink).toHaveAttribute('rel', 'noopener noreferrer');
        }

        expect(
            within(footer).queryByRole('link', {
                name: /TikTok|Snapchat/i,
            }),
        ).not.toBeInTheDocument();

        expect(
            within(footer).getByRole('link', { name: 'WhatsApp support' }),
        ).toHaveAttribute('href', 'https://wa.me/966537998099');
        expect(
            within(footer).getByRole('link', { name: 'info@arab-ut.com' }),
        ).toHaveAttribute('href', 'mailto:info@arab-ut.com');
    });

    it('keeps one legal navigation and omits the relocated provider attribution', () => {
        const footer = renderFooter();
        const bottom = footer.querySelector('.store-footer__bottom');
        const legalLine = footer.querySelector('.store-footer__legal-line');

        expect(within(footer).getAllByRole('navigation')).toHaveLength(1);
        expect(bottom?.querySelector('nav')).not.toBeInTheDocument();
        expect(legalLine).toHaveTextContent(translations.footer.ea_disclaimer);
        expect(
            footer.querySelector('.store-footer__disclaimer'),
        ).not.toBeInTheDocument();
        expect(
            footer.querySelector('a[href="https://www.exchangerate-api.com"]'),
        ).not.toBeInTheDocument();
    });

    it('shows exact payment assets, the current year, and the EA disclaimer', () => {
        vi.useFakeTimers();
        vi.setSystemTime(new Date('2031-06-15T12:00:00Z'));

        const footer = renderFooter();

        expect(footer).toHaveTextContent('Payment methods at launch');
        expect(footer).not.toHaveTextContent('Accepted payment methods');
        expect(within(footer).getAllByRole('img')).toHaveLength(4);

        for (const payment of shell.payments) {
            const image = within(footer).getByRole('img', {
                name: payment.name,
            });

            expect(image).toHaveAttribute('src', payment.imageUrl);
            expect(image).toHaveAttribute('width', String(payment.width));
            expect(image).toHaveAttribute('height', String(payment.height));
            expect(image).toHaveAttribute('loading', 'lazy');
        }

        expect(footer).toHaveTextContent(
            'Copyright © 2031 Arab UT. All rights reserved.',
        );
        expect(footer).toHaveTextContent(translations.footer.ea_disclaimer);
    });
});
