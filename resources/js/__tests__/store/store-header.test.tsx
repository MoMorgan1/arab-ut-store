import {
    cleanup,
    fireEvent,
    render,
    screen,
    within,
} from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';

import { StoreHeader } from '@/components/store/store-header';
import {
    currencyHref,
    localizedStoreHref,
} from '@/components/store/store-preferences';
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
    email: 'support@example.com',
    socials: {
        x: 'https://x.com/arabut',
        instagram: 'https://instagram.com/arabut',
    },
    payments: [],
};

const translations = {
    brand: 'Arab UT',
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
    footer: {
        description: '',
        important_links: '',
        privacy: '',
        returns: '',
        warranty: '',
        ea_backup_codes: '',
        terms: '',
        customer_service: '',
        whatsapp: '',
        payment_methods: '',
        legal_navigation: '',
        copyright: '',
        ea_disclaimer: '',
    },
    simple_pages: {
        eyebrow: '',
        back_home: '',
        cart: { title: '', body: '' },
        sbc: { title: '', body: '' },
        fut_champions: { title: '', body: '' },
        privacy: { title: '', body: '' },
        returns: { title: '', body: '' },
        warranty: { title: '', body: '' },
        ea_backup_codes: { title: '', body: '' },
        terms: { title: '', body: '' },
    },
} satisfies StoreShellTranslations;

function renderHeader(currentUrl = '/en') {
    return render(
        <StoreHeader
            currentUrl={currentUrl}
            direction="ltr"
            displayCurrencies={['SAR', 'USD', 'EUR']}
            displayCurrency="USD"
            locale="en"
            shell={shell}
            translations={translations}
        />,
    );
}

afterEach(cleanup);

describe('store preference URL helpers', () => {
    it('preserves the equivalent path, query, and hash when localizing', () => {
        expect(
            localizedStoreHref('/en/privacy?currency=USD#details', 'ar'),
        ).toBe('/privacy?currency=USD#details');
        expect(localizedStoreHref('/privacy?currency=SAR#details', 'en')).toBe(
            '/en/privacy?currency=SAR#details',
        );
    });

    it('changes only the display currency URL parameter', () => {
        expect(
            currencyHref(
                '/en/privacy?campaign=spring&currency=USD#details',
                'SAR',
            ),
        ).toBe('/en/privacy?campaign=spring&currency=SAR#details');
    });
});

describe('StoreHeader', () => {
    it('renders the WordPress two-row hierarchy and fixed navigation order', () => {
        renderHeader();

        const banner = screen.getByRole('banner');
        const primaryNav = within(banner).getByRole('navigation', {
            name: 'Primary navigation',
        });

        expect(banner.querySelector('.store-header__top')).toBeVisible();
        expect(primaryNav).toBeVisible();
        expect(
            within(primaryNav)
                .getAllByRole('link')
                .map((link) => link.textContent),
        ).toEqual([
            'Home',
            'Coins',
            'SBCMost requested',
            'FUT Champions',
            'WhatsApp',
        ]);
        expect(
            screen.getByRole('link', { name: /Coins/ }).querySelector('img'),
        ).toHaveAttribute('src', '/images/store/coins/ut-coin-80.webp');
        expect(
            screen.getByRole('link', { name: /SBC/ }).querySelector('img'),
        ).toHaveAttribute('src', '/images/store/navigation/logo-sbc-96.webp');
        expect(
            screen
                .getByRole('link', { name: /FUT Champions/ })
                .querySelector('img'),
        ).toHaveAttribute(
            'src',
            '/images/store/navigation/logo-champions-80.webp',
        );
        expect(
            screen.getByRole('link', { name: 'Home' }).querySelector('svg'),
        ).toBeInTheDocument();
        expect(
            screen.getByRole('link', { name: 'WhatsApp' }).querySelector('svg'),
        ).toBeInTheDocument();
        expect(document.querySelector('a[href="#"]')).not.toBeInTheDocument();
    });

    it('preserves the WordPress wordmark treatment and accessible brand name', () => {
        renderHeader();

        const wordmark = screen.getByRole('link', { name: 'Arab UT' });
        const crest = wordmark.querySelector('img');

        expect(wordmark).toHaveClass('store-wordmark');
        expect(wordmark).toHaveAccessibleName('Arab UT');
        expect(crest).toHaveAttribute('width', '48');
        expect(crest).toHaveAttribute('height', '48');
        expect(within(wordmark).getByText('Arab')).toHaveClass(
            'store-wordmark__name',
        );
        expect(within(wordmark).getByText('UT')).toHaveClass(
            'store-wordmark__accent',
        );
    });

    it('keeps the closed preferences trigger icon-only and exposes the selected currency when open', () => {
        renderHeader();

        const trigger = screen.getByRole('button', {
            name: 'Display preferences',
        });

        expect(trigger).toHaveAccessibleName('Display preferences');
        expect(trigger).not.toHaveTextContent('USD');
        expect(trigger.querySelector('svg')).toBeInTheDocument();

        fireEvent.click(trigger);

        expect(
            within(
                screen.getByRole('dialog', {
                    name: 'Display preferences',
                }),
            ).getByRole('link', { name: 'USD' }),
        ).toHaveAttribute('aria-current', 'page');
    });

    it('uses the exact safe WhatsApp destination and touch-target contract', () => {
        renderHeader();

        const whatsapp = screen.getByRole('link', { name: 'WhatsApp' });

        expect(whatsapp).toHaveAttribute('href', 'https://wa.me/966537998099');
        expect(whatsapp).toHaveAttribute('target', '_blank');
        expect(whatsapp).toHaveAttribute('rel', 'noopener noreferrer');
        expect(whatsapp).toHaveClass('store-primary-nav__whatsapp-target');
    });

    it('uses shell destinations for guest account and cart with a visible zero count', () => {
        renderHeader();

        expect(screen.getByRole('link', { name: 'Account' })).toHaveAttribute(
            'href',
            '/en/my-account',
        );
        expect(screen.getByRole('link', { name: 'Cart' })).toHaveAttribute(
            'href',
            '/en/cart',
        );
        expect(screen.getByText('0')).toBeInTheDocument();
    });

    it.each([
        ['/en', 'Home', 'page'],
        ['/en?currency=USD#coins', 'Coins', 'location'],
        ['/en/sbc?currency=USD', 'SBC', 'page'],
        ['/en/fut-champions?currency=USD', 'FUT Champions', 'page'],
    ])(
        'marks %s as the active %s destination',
        (currentUrl, accessibleName, current) => {
            renderHeader(currentUrl);

            expect(
                screen.getByRole('link', {
                    name: accessibleName === 'SBC' ? /SBC/ : accessibleName,
                }),
            ).toHaveAttribute('aria-current', current);
        },
    );

    it('preserves the current route in currency and language links', () => {
        renderHeader('/en/privacy?campaign=spring&currency=USD#details');

        fireEvent.click(
            screen.getByRole('button', { name: 'Display preferences' }),
        );

        const dialog = screen.getByRole('dialog', {
            name: 'Display preferences',
        });

        expect(
            within(dialog).getByRole('link', { name: 'SAR' }),
        ).toHaveAttribute(
            'href',
            '/en/privacy?campaign=spring&currency=SAR#details',
        );
        expect(
            within(dialog).getByRole('link', { name: 'العربية' }),
        ).toHaveAttribute(
            'href',
            '/privacy?campaign=spring&currency=USD#details',
        );
    });

    it('closes on Escape and restores focus to the trigger', () => {
        renderHeader();
        const trigger = screen.getByRole('button', {
            name: 'Display preferences',
        });

        fireEvent.click(trigger);
        expect(trigger).toHaveAttribute('aria-expanded', 'true');
        fireEvent.keyDown(document, { key: 'Escape' });

        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
        expect(trigger).toHaveAttribute('aria-expanded', 'false');
        expect(trigger).toHaveFocus();
    });

    it('closes on an outside pointer down without stealing focus', () => {
        renderHeader();
        const trigger = screen.getByRole('button', {
            name: 'Display preferences',
        });

        fireEvent.click(trigger);
        fireEvent.pointerDown(document.body);

        expect(screen.queryByRole('dialog')).not.toBeInTheDocument();
        expect(trigger).toHaveAttribute('aria-expanded', 'false');
    });
});
