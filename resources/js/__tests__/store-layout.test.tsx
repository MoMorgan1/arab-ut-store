import {
    act,
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
            cart_added: {
                title: 'Added to cart',
                in_cart: ':count items in your cart · :total',
                checkout: 'Checkout',
                cart: 'Cart',
                dismiss: 'Dismiss',
                duplicate_title: 'Already in your cart',
                duplicate_hint:
                    'To change the options, remove it from the cart and add it again',
                open_cart: 'Open cart',
            },
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
            preferences: {
                exchange_rate_attribution: 'Rates By Exchange Rate API',
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
                copyright: '',
                ea_disclaimer: '',
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
                cartCount={0}
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
                cartCount={0}
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

        const wordmark = screen.getByRole('link', { name: 'Arab UT' });

        expect(wordmark).toHaveTextContent('Arab UT');
        expect(within(wordmark).getByText('Arab')).toHaveClass(
            'store-wordmark__name',
        );
        expect(within(wordmark).getByText('UT')).toHaveClass(
            'store-wordmark__accent',
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
                cartCount={0}
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

    it('shows a timed mini-cart sheet with the count, total and next actions', () => {
        vi.useFakeTimers();
        render(
            <StoreLayout
                cartCount={0}
                currentUrl="/en/sbc/icon-challenge"
                locale="en"
                storeShell={storeShell}
                direction="ltr"
                displayCurrency="SAR"
                displayCurrencies={['SAR']}
                ui={englishUi}
            >
                <p>Product details remain visible</p>
            </StoreLayout>,
        );

        fireEvent(
            window,
            new CustomEvent('arabut:cart-added', {
                detail: {
                    cartCount: 3,
                    cartTotalHalalah: 61_000,
                    cartUrl: '/en/cart',
                    imageAlt: 'Icon Challenge artwork',
                    imageUrl: '/images/icon-challenge.webp',
                    itemLabel: 'Icon Challenge',
                    priceLabel: 'SAR 125.00',
                    selectionLabel: '5 completions · PC',
                    variant: 'added',
                },
            }),
        );

        const notification = screen.getByRole('status');

        expect(notification).toHaveClass('store-cart-sheet');
        expect(notification).toHaveTextContent('Added to cart');
        expect(notification).toHaveTextContent(
            '3 items in your cart · SAR 610.00',
        );
        expect(notification).toHaveTextContent('Icon Challenge');
        expect(notification).toHaveTextContent('5 completions');
        expect(notification).toHaveTextContent('PC');
        expect(notification).toHaveTextContent('SAR 125.00');
        expect(
            notification.querySelector('.store-cart-sheet__progress'),
        ).not.toBeNull();
        expect(
            within(notification).getByRole('link', { name: 'Checkout' }),
        ).toHaveAttribute('href', '/en/cart');
        expect(
            within(notification).getByRole('link', { name: 'Cart' }),
        ).toHaveAttribute('href', '/en/cart');
        expect(
            screen.getByText('Product details remain visible'),
        ).toBeVisible();

        act(() => vi.advanceTimersByTime(5_000));
        act(() => vi.advanceTimersByTime(180));
        expect(screen.queryByRole('status')).not.toBeInTheDocument();

        vi.useRealTimers();
    });

    it('lets shoppers dismiss the cart sheet without leaving the page', () => {
        vi.useFakeTimers();
        render(
            <StoreLayout
                cartCount={0}
                currentUrl="/en/sbc/icon-challenge"
                locale="en"
                storeShell={storeShell}
                direction="ltr"
                displayCurrency="SAR"
                displayCurrencies={['SAR']}
                ui={englishUi}
            >
                <p>Product details remain visible</p>
            </StoreLayout>,
        );

        fireEvent(
            window,
            new CustomEvent('arabut:cart-added', {
                detail: {
                    cartCount: 1,
                    cartTotalHalalah: 12_500,
                    cartUrl: '/en/cart',
                    imageAlt: 'Icon Challenge artwork',
                    imageUrl: '/images/icon-challenge.webp',
                    itemLabel: 'Icon Challenge',
                },
            }),
        );

        fireEvent.click(screen.getByRole('button', { name: 'Dismiss' }));
        act(() => vi.advanceTimersByTime(180));
        expect(screen.queryByRole('status')).not.toBeInTheDocument();

        vi.useRealTimers();
    });

    it('closes the cart sheet on Escape', () => {
        vi.useFakeTimers();
        render(
            <StoreLayout
                cartCount={0}
                currentUrl="/en/sbc/icon-challenge"
                locale="en"
                storeShell={storeShell}
                direction="ltr"
                displayCurrency="SAR"
                displayCurrencies={['SAR']}
                ui={englishUi}
            >
                <p>Product details remain visible</p>
            </StoreLayout>,
        );

        fireEvent(
            window,
            new CustomEvent('arabut:cart-added', {
                detail: {
                    cartCount: 1,
                    cartTotalHalalah: 12_500,
                    cartUrl: '/en/cart',
                    imageAlt: 'Icon Challenge artwork',
                    imageUrl: '/images/icon-challenge.webp',
                    itemLabel: 'Icon Challenge',
                },
            }),
        );

        fireEvent.keyDown(document, { key: 'Escape' });
        act(() => vi.advanceTimersByTime(180));
        expect(screen.queryByRole('status')).not.toBeInTheDocument();

        vi.useRealTimers();
    });

    it('shows the amber duplicate variant with a single open-cart action', () => {
        render(
            <StoreLayout
                cartCount={1}
                currentUrl="/en/sbc/icon-challenge"
                locale="en"
                storeShell={storeShell}
                direction="ltr"
                displayCurrency="SAR"
                displayCurrencies={['SAR']}
                ui={englishUi}
            >
                <p>Product details remain visible</p>
            </StoreLayout>,
        );

        fireEvent(
            window,
            new CustomEvent('arabut:cart-added', {
                detail: {
                    cartUrl: '/en/cart',
                    imageAlt: 'Icon Challenge artwork',
                    imageUrl: '/images/icon-challenge.webp',
                    itemLabel: 'Icon Challenge',
                    selectionLabel: '5 completions · PC',
                    variant: 'duplicate',
                },
            }),
        );

        const notification = screen.getByRole('status');

        expect(notification).toHaveClass('store-cart-sheet--duplicate');
        expect(notification).toHaveTextContent('Already in your cart');
        expect(notification).toHaveTextContent(
            'To change the options, remove it from the cart and add it again',
        );
        expect(screen.getByRole('link', { name: 'Open cart' })).toHaveAttribute(
            'href',
            '/en/cart',
        );
        expect(
            screen.queryByRole('link', { name: 'Checkout' }),
        ).not.toBeInTheDocument();
    });

    it('keeps the cart notification visible while shoppers interact with it', () => {
        vi.useFakeTimers();
        render(
            <StoreLayout
                cartCount={0}
                currentUrl="/en/sbc/icon-challenge"
                locale="en"
                storeShell={storeShell}
                direction="ltr"
                displayCurrency="SAR"
                displayCurrencies={['SAR']}
                ui={englishUi}
            >
                <p>Product details remain visible</p>
            </StoreLayout>,
        );

        fireEvent(
            window,
            new CustomEvent('arabut:cart-added', {
                detail: {
                    cartUrl: '/en/cart',
                    imageAlt: 'Icon Challenge artwork',
                    imageUrl: '/images/icon-challenge.webp',
                    itemLabel: 'Icon Challenge',
                },
            }),
        );

        const notification = screen.getByRole('status');
        fireEvent.mouseEnter(notification);

        act(() => vi.advanceTimersByTime(5_000));
        expect(screen.getByRole('status')).toBeVisible();

        fireEvent.mouseLeave(notification);
        act(() => vi.advanceTimersByTime(5_000));
        expect(screen.getByRole('status')).toBeInTheDocument();
        act(() => vi.advanceTimersByTime(180));
        expect(screen.queryByRole('status')).not.toBeInTheDocument();

        vi.useRealTimers();
    });

    it('resumes the cart notification with the remaining timer after hover', () => {
        vi.useFakeTimers();
        render(
            <StoreLayout
                cartCount={0}
                currentUrl="/en/sbc/icon-challenge"
                locale="en"
                storeShell={storeShell}
                direction="ltr"
                displayCurrency="SAR"
                displayCurrencies={['SAR']}
                ui={englishUi}
            >
                <p>Product details remain visible</p>
            </StoreLayout>,
        );

        fireEvent(
            window,
            new CustomEvent('arabut:cart-added', {
                detail: {
                    cartUrl: '/en/cart',
                    imageAlt: 'Icon Challenge artwork',
                    imageUrl: '/images/icon-challenge.webp',
                    itemLabel: 'Icon Challenge',
                },
            }),
        );

        const notification = screen.getByRole('status');
        act(() => vi.advanceTimersByTime(2_000));
        fireEvent.mouseEnter(notification);
        expect(notification).toHaveAttribute('data-paused', 'true');

        act(() => vi.advanceTimersByTime(10_000));
        expect(screen.getByRole('status')).toBeVisible();

        fireEvent.mouseLeave(notification);
        expect(notification).toHaveAttribute('data-paused', 'false');
        act(() => vi.advanceTimersByTime(2_999));
        expect(screen.getByRole('status')).toBeVisible();
        act(() => vi.advanceTimersByTime(1));
        expect(screen.getByRole('status')).toBeInTheDocument();
        act(() => vi.advanceTimersByTime(180));
        expect(screen.queryByRole('status')).not.toBeInTheDocument();

        vi.useRealTimers();
    });

    it('pauses the cart notification while a shopper touches it', () => {
        vi.useFakeTimers();
        render(
            <StoreLayout
                cartCount={0}
                currentUrl="/en/sbc/icon-challenge"
                locale="en"
                storeShell={storeShell}
                direction="ltr"
                displayCurrency="SAR"
                displayCurrencies={['SAR']}
                ui={englishUi}
            >
                <p>Product details remain visible</p>
            </StoreLayout>,
        );

        fireEvent(
            window,
            new CustomEvent('arabut:cart-added', {
                detail: {
                    cartUrl: '/en/cart',
                    imageAlt: 'Icon Challenge artwork',
                    imageUrl: '/images/icon-challenge.webp',
                    itemLabel: 'Icon Challenge',
                },
            }),
        );

        const notification = screen.getByRole('status');
        fireEvent.pointerDown(notification, { pointerType: 'touch' });
        expect(notification).toHaveAttribute('data-paused', 'true');
        act(() => vi.advanceTimersByTime(5_000));
        expect(screen.getByRole('status')).toBeVisible();

        fireEvent.pointerUp(notification, { pointerType: 'touch' });
        expect(notification).toHaveAttribute('data-paused', 'false');
        act(() => vi.advanceTimersByTime(5_000));
        expect(screen.getByRole('status')).toBeInTheDocument();
        act(() => vi.advanceTimersByTime(180));
        expect(screen.queryByRole('status')).not.toBeInTheDocument();

        vi.useRealTimers();
    });

    it('dismisses the sheet on a swipe-up of more than 40px', () => {
        vi.useFakeTimers();
        render(
            <StoreLayout
                cartCount={0}
                currentUrl="/en/sbc/icon-challenge"
                locale="en"
                storeShell={storeShell}
                direction="ltr"
                displayCurrency="SAR"
                displayCurrencies={['SAR']}
                ui={englishUi}
            >
                <p>Product details remain visible</p>
            </StoreLayout>,
        );

        fireEvent(
            window,
            new CustomEvent('arabut:cart-added', {
                detail: {
                    cartCount: 1,
                    cartTotalHalalah: 12_500,
                    cartUrl: '/en/cart',
                    imageAlt: 'Icon Challenge artwork',
                    imageUrl: '/images/icon-challenge.webp',
                    itemLabel: 'Icon Challenge',
                },
            }),
        );

        const notification = screen.getByRole('status');
        fireEvent.pointerDown(notification, {
            pointerType: 'touch',
            clientY: 300,
        });
        fireEvent.pointerUp(notification, {
            pointerType: 'touch',
            clientY: 200,
        });
        act(() => vi.advanceTimersByTime(180));
        expect(screen.queryByRole('status')).not.toBeInTheDocument();

        vi.useRealTimers();
    });
});
