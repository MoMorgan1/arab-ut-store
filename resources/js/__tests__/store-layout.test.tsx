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
        locale: 'en',
        ui: {
            brand: 'Arab UT',
            browse_services: 'Browse services',
            checkout_notice:
                'All final prices and checkout are in Saudi Riyal (:currency).',
            currency: 'Currency',
            currency_selector: 'Choose display currency',
            home_title: 'Home',
            language: 'العربية',
            service_notice: 'Trusted FC 27 services for players worldwide',
            skip_to_content: 'Skip to content',
            store_tools: 'Store tools',
        },
    },
    url: '/en?campaign=spring&currency=USD',
}));

vi.mock('@inertiajs/react', () => ({
    Head: ({ title }: { title: string }) => (
        <title>{`${title} - Arab UT`}</title>
    ),
    usePage: () => mockPage,
}));

const englishUi = mockPage.props.ui;
const arabicUi = {
    ...englishUi,
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
                currentUrl="/ar?campaign=spring&currency=EUR#services"
                locale="ar"
                direction="rtl"
                displayCurrency="EUR"
                ui={arabicUi}
            >
                <p>محتوى المتجر</p>
            </StoreLayout>,
        );

        expect(screen.getByRole('banner')).toHaveAttribute('dir', 'rtl');
        expect(screen.getByRole('link', { name: 'English' })).toHaveAttribute(
            'href',
            '/en?campaign=spring&currency=EUR#services',
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
                currentUrl="/en?campaign=spring&currency=USD#services"
                locale="en"
                direction="ltr"
                displayCurrency="USD"
                ui={englishUi}
            >
                <p>Store content</p>
            </StoreLayout>,
        );

        const languageLink = screen.getByRole('link', { name: 'العربية' });

        expect(languageLink).toHaveAttribute(
            'href',
            '/?campaign=spring&currency=USD#services',
        );
        expect(languageLink).toHaveAttribute('lang', 'ar');
        expect(languageLink).toHaveAttribute('dir', 'rtl');
    });

    it('offers every supported currency while preserving unrelated URL state', () => {
        render(
            <StoreLayout
                currentUrl="/en?campaign=spring&coupon=SAVE&currency=USD#services"
                locale="en"
                direction="ltr"
                displayCurrency="USD"
                ui={englishUi}
            >
                <p>Store content</p>
            </StoreLayout>,
        );

        const selector = screen.getByRole('navigation', {
            name: 'Choose display currency',
        });
        const selectorToggle = within(selector).getByLabelText(
            'Choose display currency: USD',
        );

        fireEvent.click(selectorToggle);

        expect(selectorToggle.closest('details')).toHaveAttribute('open');

        for (const currency of ['SAR', 'USD', 'EUR', 'GBP']) {
            const currencyLink = within(selector).getByRole('link', {
                name: currency,
            });

            expect(currencyLink).toHaveAttribute(
                'href',
                `/en?campaign=spring&coupon=SAVE&currency=${currency}#services`,
            );

            if (currency === 'USD') {
                expect(currencyLink).toHaveAttribute('aria-current', 'page');
            } else {
                expect(currencyLink).not.toHaveAttribute('aria-current');
            }
        }
    });

    it('uses a page-specific title and sends the service CTA to a real section', () => {
        render(<StoreHome />);

        expect(document.title).toBe('Home - Arab UT');
        expect(
            screen.getByRole('link', { name: 'Browse services' }),
        ).toHaveAttribute('href', '#services');
        expect(document.querySelector('section#services')).toBeInTheDocument();
    });
});
