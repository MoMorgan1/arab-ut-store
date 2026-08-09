import {
    cleanup,
    fireEvent,
    render,
    screen,
    within,
} from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';

import StoreLayout from '@/layouts/store-layout';
import StoreHome from '@/pages/store/home';
const mockPage = vi.hoisted(() => ({
    props: {
        checkoutCurrency: 'SAR',
        direction: 'ltr',
        displayCurrency: 'USD',
        displayCurrencies: ['SAR', 'USD', 'EUR', 'GBP'],
        locale: 'en',
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
            checkout_notice:
                'All final prices and checkout are in Saudi Riyal (:currency).',
            currency: 'Currency',
            currency_selector: 'Choose display currency',
            home_title: 'Home',
            language: 'العربية',
            service_notice: 'Trusted FC 27 services for players worldwide',
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
        },
    },
    url: '/en?campaign=spring&currency=USD',
}));

const storeShell = mockPage.props.storeShell;

vi.mock('@inertiajs/react', () => ({
    Head: ({ title }: { title: string }) => <title>{title}</title>,
    usePage: () => mockPage,
}));

const englishUi = mockPage.props.ui;
const arabicUi = {
    ...englishUi,
    brand: 'عرب التيميت',
    currency: 'العملة',
    currency_selector: 'اختر عملة العرض',
    language: 'English',
    skip_to_content: 'انتقل إلى المحتوى',
    store_tools: 'أدوات المتجر',
};

afterEach(() => {
    cleanup();
    document.title = '';
});

describe('StoreLayout', () => {
    it('links from Arabic to English with query state and language metadata', () => {
        render(
            <StoreLayout
                currentUrl="/ar?campaign=spring&currency=EUR#offers"
                locale="ar"
                storeShell={{
                    ...storeShell,
                    homeUrl: '/',
                    coinsUrl: '/#coins',
                }}
                direction="rtl"
                displayCurrency="EUR"
                displayCurrencies={['SAR', 'USD', 'EUR', 'GBP']}
                ui={arabicUi}
            >
                <p>محتوى المتجر</p>
            </StoreLayout>,
        );

        expect(screen.getByRole('banner')).toHaveAttribute('dir', 'rtl');
        expect(
            screen.getByRole('link', { name: 'عرب التيميت' }),
        ).toHaveTextContent('عرب التيميت');

        fireEvent.click(
            screen.getByRole('button', { name: 'Display preferences' }),
        );
        expect(screen.getByRole('link', { name: 'English' })).toHaveAttribute(
            'href',
            '/en?campaign=spring&currency=EUR#offers',
        );
        expect(screen.getByRole('link', { name: 'English' })).toHaveAttribute(
            'lang',
            'en',
        );
        expect(screen.getByRole('link', { name: 'English' })).toHaveAttribute(
            'dir',
            'ltr',
        );
    });

    it('links from English to the default Arabic route with query state and metadata', () => {
        render(
            <StoreLayout
                currentUrl="/en?campaign=spring&currency=USD#offers"
                locale="en"
                storeShell={storeShell}
                direction="ltr"
                displayCurrency="USD"
                displayCurrencies={['SAR', 'USD', 'EUR', 'GBP']}
                ui={englishUi}
            >
                <p>Store content</p>
            </StoreLayout>,
        );

        fireEvent.click(
            screen.getByRole('button', { name: 'Display preferences' }),
        );
        const languageLink = screen.getByRole('link', { name: 'العربية' });

        expect(screen.getByRole('link', { name: 'Arab UT' })).toHaveTextContent(
            'Arab UT',
        );

        expect(languageLink).toHaveAttribute(
            'href',
            '/?campaign=spring&currency=USD#offers',
        );
        expect(languageLink).toHaveAttribute('lang', 'ar');
        expect(languageLink).toHaveAttribute('dir', 'rtl');
    });

    it('renders only supplied currencies while preserving unrelated URL state', () => {
        render(
            <StoreLayout
                currentUrl="/en?campaign=spring&coupon=SAVE&currency=USD#offers"
                locale="en"
                storeShell={storeShell}
                direction="ltr"
                displayCurrency="USD"
                displayCurrencies={['USD', 'CAD']}
                ui={englishUi}
            >
                <p>Store content</p>
            </StoreLayout>,
        );

        const selectorToggle = screen.getByRole('button', {
            name: 'Display preferences',
        });

        fireEvent.click(selectorToggle);

        expect(selectorToggle).toHaveAttribute('aria-expanded', 'true');

        const selector = screen.getByRole('dialog', {
            name: 'Display preferences',
        });

        for (const currency of ['USD', 'CAD']) {
            const currencyLink = within(selector).getByRole('link', {
                name: currency,
            });

            expect(currencyLink).toHaveAttribute(
                'href',
                `/en?campaign=spring&coupon=SAVE&currency=${currency}#offers`,
            );

            if (currency === 'USD') {
                expect(currencyLink).toHaveAttribute('aria-current', 'page');
            } else {
                expect(currencyLink).not.toHaveAttribute('aria-current');
            }
        }

        expect(
            within(selector).queryByRole('link', { name: 'SAR' }),
        ).not.toBeInTheDocument();
        expect(
            within(selector).queryByRole('link', { name: 'EUR' }),
        ).not.toBeInTheDocument();
    });

    it('uses a page-specific title without rendering a dead-end service CTA', () => {
        render(<StoreHome />);

        expect(document.title).toBe('Home');
        expect(
            document.querySelector('a[href="#services"]'),
        ).not.toBeInTheDocument();
        expect(
            document.querySelector('section#services'),
        ).not.toBeInTheDocument();
    });

    it('does not expose the checkout currency policy on the homepage', () => {
        render(<StoreHome />);

        expect(
            screen.queryByText(
                'All final prices and checkout are in Saudi Riyal (SAR).',
            ),
        ).not.toBeInTheDocument();
    });
});
