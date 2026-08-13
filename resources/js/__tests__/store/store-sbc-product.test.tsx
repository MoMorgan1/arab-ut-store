import {
    act,
    cleanup,
    fireEvent,
    render,
    screen,
    waitFor,
} from '@testing-library/react';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';

import { SbcCartRequestError } from '@/lib/sbc-cart-api';
import StoreCatalogProduct from '@/pages/store/catalog-product';

const mocks = vi.hoisted(() => ({ submit: vi.fn(), visit: vi.fn() }));
const page = vi.hoisted(() => ({
    props: {} as Record<string, unknown>,
    url: '/en/sbc/icon-challenge?variant=01K00000000000000000000004',
}));

vi.mock('@inertiajs/react', () => ({
    Head: ({ title }: { title: string }) => <title>{title}</title>,
    router: { visit: mocks.visit },
    usePage: () => page,
}));
vi.mock('@/lib/sbc-cart-api', async () => {
    const original =
        await vi.importActual<Record<string, unknown>>('@/lib/sbc-cart-api');

    return { ...original, submitSbcCart: mocks.submit };
});

afterEach(() => {
    cleanup();
    vi.useRealTimers();
});

beforeEach(() => {
    mocks.submit.mockReset();
    mocks.visit.mockReset();
    mocks.submit.mockResolvedValue({
        cartCount: 1,
        cartItemId: '01K00000000000000000000005',
        cartUrl: '/en/cart',
    });
    page.url = '/en/sbc/icon-challenge?variant=01K00000000000000000000004';
    page.props = sbcProductProps();
});

it('matches the compact SBC platform and three-code credential hierarchy', () => {
    render(<StoreCatalogProduct />);

    expect(
        screen.getByRole('heading', { name: 'Icon Challenge' }),
    ).toBeVisible();
    expect(
        screen.getByRole('group', { name: 'Choose platform' }),
    ).toBeVisible();
    expect(
        screen.getByRole('radio', { name: /PC.*SAR.*150.00/ }),
    ).toBeChecked();
    expect(
        screen.getByRole('heading', { name: 'EA account details' }),
    ).toBeVisible();
    expect(screen.getByLabelText('EA email')).toHaveAttribute('dir', 'ltr');
    expect(screen.getByLabelText('EA password')).toHaveAttribute(
        'type',
        'password',
    );
    expect(screen.getAllByLabelText(/Backup code [123]/)).toHaveLength(3);
    expect(screen.queryByText(/automatically deleted|24 hours/i)).toBeNull();
    expect(screen.queryByRole('button', { name: /checkout|pay/i })).toBeNull();
    expect(screen.getByText('platform')).toBeVisible();
    expect(
        document.querySelector('.sbc-product-summary__platform'),
    ).toHaveTextContent('PC');
    expect(
        screen.getByRole('heading', { name: 'You may also like' }),
    ).toBeVisible();
    expect(
        screen.getByRole('link', { name: /Related challenge/ }),
    ).toHaveAttribute('href', '/en/sbc/related-challenge');
});

it('keeps a direct SBC visit unselected and places its identity above the product image', () => {
    page.url = '/en/sbc/icon-challenge';
    render(<StoreCatalogProduct />);

    expect(document.querySelector('.store-catalog-product')).toHaveClass(
        'store-catalog-product--sbc',
    );
    expect(
        screen
            .getByRole('heading', { name: 'Icon Challenge' })
            .closest('header'),
    ).toHaveClass('store-catalog-product__identity');
    expect(
        document.querySelector('.store-catalog-product__media-column'),
    ).toContainElement(document.querySelector('.store-catalog-product__image'));
    screen
        .getAllByRole('radio')
        .forEach((radio) => expect(radio).not.toBeChecked());
    expect(screen.getByRole('button', { name: 'Add to cart' })).toBeDisabled();
});

it('focuses the first invalid field and submits exactly three credentials in memory', async () => {
    render(<StoreCatalogProduct />);

    fireEvent.click(screen.getByRole('button', { name: 'Add to cart' }));
    expect(screen.getByLabelText('EA email')).toHaveFocus();
    expect(screen.getByLabelText('EA email')).toHaveAttribute(
        'aria-describedby',
        'sbc-ea-email-error',
    );
    expect(mocks.submit).not.toHaveBeenCalled();

    fireEvent.change(screen.getByLabelText('EA email'), {
        target: { value: 'owner@example.test' },
    });
    fireEvent.change(screen.getByLabelText('EA password'), {
        target: { value: 'opaque password' },
    });
    ['93000001', '93000002', '93000003'].forEach((code, index) => {
        fireEvent.change(screen.getByLabelText(`Backup code ${index + 1}`), {
            target: { value: code },
        });
    });
    fireEvent.click(screen.getByRole('button', { name: 'Add to cart' }));

    await waitFor(() => expect(mocks.submit).toHaveBeenCalledTimes(1));
    expect(mocks.submit.mock.calls[0][0]).toMatchObject({
        cartUrl: '/en/cart/items/sbc',
        variantId: '01K00000000000000000000004',
        credentials: {
            eaEmail: 'owner@example.test',
            eaPassword: 'opaque password',
            backupCodes: ['93000001', '93000002', '93000003'],
        },
    });
    expect(localStorage).toHaveLength(0);
    expect(sessionStorage).toHaveLength(0);
});

it('reveals the password accessibly and gives visible success feedback before visiting the cart', async () => {
    vi.useFakeTimers();
    render(<StoreCatalogProduct />);
    const password = screen.getByLabelText('EA password');

    fireEvent.click(screen.getByRole('button', { name: 'Show password' }));
    expect(password).toHaveAttribute('type', 'text');

    fireEvent.change(screen.getByLabelText('EA email'), {
        target: { value: 'owner@example.test' },
    });
    fireEvent.change(password, { target: { value: 'opaque password' } });
    ['93000001', '93000002', '93000003'].forEach((code, index) => {
        fireEvent.change(screen.getByLabelText(`Backup code ${index + 1}`), {
            target: { value: code },
        });
    });
    fireEvent.click(screen.getByRole('button', { name: 'Add to cart' }));
    await vi.waitFor(() => expect(mocks.submit).toHaveBeenCalledTimes(1));
    await act(async () => {
        await Promise.resolve();
    });
    expect(screen.getByRole('status')).toHaveTextContent('Added securely');
    expect(mocks.visit).not.toHaveBeenCalled();

    await vi.advanceTimersByTimeAsync(450);
    expect(mocks.visit).toHaveBeenCalledWith('/en/cart');
});

it('locks the selected platform and credentials while the add request is in flight', async () => {
    let completeRequest:
        | ((value: {
              cartCount: number;
              cartItemId: string;
              cartUrl: string;
          }) => void)
        | undefined;
    mocks.submit.mockReturnValue(
        new Promise((resolve) => {
            completeRequest = resolve;
        }),
    );
    render(<StoreCatalogProduct />);
    fillValidCredentials();

    fireEvent.click(screen.getByRole('button', { name: 'Add to cart' }));
    await waitFor(() => expect(mocks.submit).toHaveBeenCalledTimes(1));

    expect(screen.getByLabelText('EA email')).toBeDisabled();
    expect(screen.getByLabelText('EA password')).toBeDisabled();
    screen
        .getAllByLabelText(/Backup code [123]/)
        .forEach((input) => expect(input).toBeDisabled());
    expect(screen.getByRole('radio', { name: /PC/ })).toBeDisabled();
    fireEvent.click(screen.getByRole('button', { name: 'Adding…' }));
    expect(mocks.submit).toHaveBeenCalledTimes(1);

    completeRequest?.({
        cartCount: 1,
        cartItemId: '01K00000000000000000000005',
        cartUrl: '/en/cart',
    });
    await waitFor(() => expect(screen.getByRole('status')).toBeVisible());
});

it('keeps a transport retry key and focuses only an allowlisted rejected field', async () => {
    mocks.submit
        .mockRejectedValueOnce(
            new SbcCartRequestError('transport_error', 0, false),
        )
        .mockRejectedValueOnce(
            new SbcCartRequestError('validation_error', 422, true, ['email']),
        );
    render(<StoreCatalogProduct />);
    fillValidCredentials();

    fireEvent.click(screen.getByRole('button', { name: 'Add to cart' }));
    await waitFor(() => expect(screen.getByRole('alert')).toBeVisible());
    const firstKey = mocks.submit.mock.calls[0][0].idempotencyKey;

    fireEvent.click(screen.getByRole('button', { name: 'Add to cart' }));
    await waitFor(() => expect(mocks.submit).toHaveBeenCalledTimes(2));
    expect(mocks.submit.mock.calls[1][0].idempotencyKey).toBe(firstKey);
    await waitFor(() =>
        expect(screen.getByLabelText('EA email')).toHaveFocus(),
    );
});

function fillValidCredentials() {
    fireEvent.change(screen.getByLabelText('EA email'), {
        target: { value: 'owner@example.test' },
    });
    fireEvent.change(screen.getByLabelText('EA password'), {
        target: { value: 'opaque password' },
    });
    ['93000001', '93000002', '93000003'].forEach((code, index) => {
        fireEvent.change(screen.getByLabelText(`Backup code ${index + 1}`), {
            target: { value: code },
        });
    });
}

function sbcProductProps() {
    return {
        cartCount: 0,
        direction: 'ltr',
        displayCurrency: 'SAR',
        displayCurrencies: ['SAR'],
        locale: 'en',
        backUrl: '/en/sbc',
        catalogCartUrl: '/en/cart/items/catalog',
        sbcCartUrl: '/en/cart/items/sbc',
        catalog: {
            service: 'sbc',
            product: {
                id: '01K00000000000000000000003',
                slug: 'icon-challenge',
                url: '/en/sbc/icon-challenge',
                name: 'Icon Challenge',
                description: 'We fund and complete this SBC.',
                image: null,
                price: { amountMinor: 12500, currency: 'SAR' },
                platforms: ['playstation', 'pc'],
                variants: [
                    {
                        id: '01K00000000000000000000003',
                        name: 'PS / Xbox',
                        platform: 'playstation',
                        price: { amountMinor: 12500, currency: 'SAR' },
                    },
                    {
                        id: '01K00000000000000000000004',
                        name: 'PC',
                        platform: 'pc',
                        price: { amountMinor: 15000, currency: 'SAR' },
                    },
                ],
            },
            suggestions: [
                {
                    id: '01K00000000000000000000009',
                    slug: 'related-challenge',
                    url: '/en/sbc/related-challenge',
                    name: 'Related challenge',
                    description: 'Another SBC.',
                    image: null,
                    price: { amountMinor: 9900, currency: 'SAR' },
                    platforms: ['pc'],
                    variants: [],
                },
            ],
        },
        productPage: {
            choose_option: 'Choose an option',
            platform: 'Platform',
            price: 'Price',
            add_to_cart: 'Add to cart',
            adding: 'Adding…',
            back: 'Back to challenges',
            unavailable_price: 'Unavailable',
            add_error: 'Could not add this item.',
            sbc: {
                platform_legend: 'Choose platform',
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
                related_eyebrow: 'More SBC services',
                related_title: 'You may also like',
                related_link: 'Open service',
            },
        },
        storeShell: {
            homeUrl: '/en',
            coinsUrl: '/en#coins',
            cartUrl: '/en/cart',
            sbcUrl: '/en/sbc',
            futChampionsUrl: '/en/fut-champions',
            accountUrl: '/en/login',
            privacyUrl: '/en/privacy',
            returnsUrl: '/en/returns',
            warrantyUrl: '/en/warranty',
            eaBackupCodesUrl: '/en/ea-backup-codes',
            termsUrl: '/en/terms',
            whatsappUrl: '#',
            email: '',
            socials: { x: '', instagram: '' },
            payments: [],
        },
        ui: {
            brand: 'Arab UT',
            language: 'Arabic',
            currency_selector: 'Currency',
            home_title: 'Home',
            skip_to_content: 'Skip',
            store_tools: 'Tools',
            header: {
                primary_navigation: 'Primary',
                preferences: 'Preferences',
                home: 'Home',
                coins: 'Coins',
                sbc: 'SBC',
                fut_champions: 'FUT',
                most_requested: 'Most',
                whatsapp: 'WhatsApp',
                cart: 'Cart',
                account: 'Account',
            },
            preferences: { exchange_rate_attribution: 'Rates' },
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
    };
}
