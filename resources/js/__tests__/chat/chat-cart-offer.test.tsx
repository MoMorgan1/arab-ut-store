import {
    cleanup,
    fireEvent,
    render,
    screen,
    waitFor,
} from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { ChatCartOffer } from '@/components/chat/chat-cart-offer';
import type { ChatCoinsCartOffer } from '@/lib/chat-cart';

vi.mock('@inertiajs/react', () => ({
    Link: ({ children, href }: { children: React.ReactNode; href: string }) => (
        <a href={href}>{children}</a>
    ),
}));

const consoleOffer: ChatCoinsCartOffer = {
    service: 'coins',
    platform: 'playstation',
    delivery: 'normal',
    quantity: 1_000_000,
};

const fastOffer: ChatCoinsCartOffer = { ...consoleOffer, delivery: 'fast' };

/** The one cached map the widget fetches for cards, shelf and cart panels. */
const PRICES = {
    'coins:playstation:normal:1000000': {
        amountMinor: 910,
        currency: 'SAR',
        unit: 'total',
    },
    'coins:playstation:fast:1000000': {
        amountMinor: 1410,
        currency: 'SAR',
        unit: 'total',
    },
};

function cartCreatedResponse() {
    return {
        status: 201,
        json: async () => ({
            data: {
                cartCount: 1,
                cartItemId: '01JZ0000000000000000000000',
                cartUrl: '/cart',
            },
        }),
    } as unknown as Response;
}

/** Every request the component made, in order, as [url, init] pairs. */
let calls: Array<[string, RequestInit | undefined]>;

function stubFetch(cartResponse: () => Response) {
    return vi.fn(async (input: RequestInfo | URL, init?: RequestInit) => {
        const url = input instanceof URL ? input.toString() : String(input);
        calls.push([url, init]);

        return cartResponse();
    });
}

function cartCalls() {
    return calls.filter(([url]) => url.includes('/cart/items/coins'));
}

/** The add button only exists once the store has a price for this selection. */
function openForm() {
    fireEvent.click(screen.getByTestId('chat-cart-start'));
}

function fillCredentials() {
    fireEvent.change(screen.getByLabelText('EA email'), {
        target: { value: 'player@example.com' },
    });
    fireEvent.change(screen.getByLabelText('EA password'), {
        target: { value: 'correct horse battery' },
    });
    ['11111111', '22222222', '33333333'].forEach((code, index) => {
        fireEvent.change(screen.getByLabelText(`Backup code ${index + 1}`), {
            target: { value: code },
        });
    });
    fireEvent.click(
        screen.getByLabelText(/Web App \/ Companion market is open/),
    );
    fireEvent.click(screen.getByLabelText(/accept the terms/));
}

beforeEach(() => {
    calls = [];
    document.head.innerHTML = '<meta name="csrf-token" content="test-token">';
    vi.stubGlobal('crypto', {
        ...globalThis.crypto,
        randomUUID: () => '11111111-2222-3333-4444-555555555555',
    });
});

afterEach(() => {
    cleanup();
    vi.unstubAllGlobals();
    vi.restoreAllMocks();
});

describe('ChatCartOffer', () => {
    it('renders nothing without an offer', () => {
        vi.stubGlobal('fetch', stubFetch(cartCreatedResponse));
        const { container } = render(
            <ChatCartOffer offer={null} locale="en" />,
        );

        expect(container).toBeEmptyDOMElement();
    });

    it('shows the live price rather than one stored in the message', () => {
        vi.stubGlobal('fetch', stubFetch(cartCreatedResponse));
        render(
            <ChatCartOffer
                offer={consoleOffer}
                locale="en"
                servicePrices={PRICES}
            />,
        );

        expect(screen.getByTestId('chat-cart-price').textContent).toContain(
            '9.10',
        );

        // No request of its own: the price comes from the map the widget
        // already fetched, so a transcript of offers costs nothing extra and
        // a pricing outage logs no console error.
        expect(calls).toHaveLength(0);
    });

    it('posts the credentials to the cart endpoint, never as a message', async () => {
        vi.stubGlobal('fetch', stubFetch(cartCreatedResponse));
        render(
            <ChatCartOffer
                offer={consoleOffer}
                locale="en"
                servicePrices={PRICES}
            />,
        );

        openForm();
        fillCredentials();
        fireEvent.click(screen.getByTestId('chat-cart-submit'));

        await waitFor(() => {
            expect(screen.getByTestId('chat-cart-added')).toBeInTheDocument();
        });

        const [, init] = cartCalls()[0] ?? [];
        const body = JSON.parse(String(init?.body));

        expect(body).toEqual({
            credentials: {
                backup_codes: ['11111111', '22222222', '33333333'],
                companion_market_open: true,
                ea_email: 'player@example.com',
                ea_password: 'correct horse battery',
                policy_accepted: true,
            },
            delivery: 'normal',
            platform: 'playstation',
            quantity: 1_000_000,
        });

        // The credential values must reach the cart endpoint and nothing else.
        expect(calls.filter(([url]) => url.includes('/chat'))).toHaveLength(0);
    });

    it('posts to the localized endpoint so the cart link matches the language', async () => {
        vi.stubGlobal('fetch', stubFetch(cartCreatedResponse));
        render(
            <ChatCartOffer
                offer={consoleOffer}
                locale="en"
                servicePrices={PRICES}
            />,
        );

        openForm();
        fillCredentials();
        fireEvent.click(screen.getByTestId('chat-cart-submit'));

        await waitFor(() => {
            expect(cartCalls()).toHaveLength(1);
        });

        expect(cartCalls()[0]?.[0]).toContain('/en/cart/items/coins');
    });

    it('clears the password from the panel once the item is in the cart', async () => {
        vi.stubGlobal('fetch', stubFetch(cartCreatedResponse));
        render(
            <ChatCartOffer
                offer={consoleOffer}
                locale="en"
                servicePrices={PRICES}
            />,
        );

        openForm();
        fillCredentials();
        fireEvent.click(screen.getByTestId('chat-cart-submit'));

        await waitFor(() => {
            expect(screen.getByTestId('chat-cart-added')).toBeInTheDocument();
        });

        expect(screen.queryByLabelText('EA password')).not.toBeInTheDocument();
        expect(screen.getByRole('link', { name: 'View cart' })).toHaveAttribute(
            'href',
            '/cart',
        );
    });

    it('will not submit an incomplete form', async () => {
        vi.stubGlobal('fetch', stubFetch(cartCreatedResponse));
        render(
            <ChatCartOffer
                offer={consoleOffer}
                locale="en"
                servicePrices={PRICES}
            />,
        );

        openForm();
        fireEvent.change(screen.getByLabelText('EA email'), {
            target: { value: 'not-an-email' },
        });
        fireEvent.click(screen.getByTestId('chat-cart-submit'));

        await waitFor(() => {
            expect(screen.getByTestId('chat-cart-error')).toBeInTheDocument();
        });

        expect(cartCalls()).toHaveLength(0);
    });

    it('marks a rejected field invalid, not merely a different colour', async () => {
        vi.stubGlobal('fetch', stubFetch(cartCreatedResponse));
        render(
            <ChatCartOffer
                offer={consoleOffer}
                locale="en"
                servicePrices={PRICES}
            />,
        );

        openForm();
        fireEvent.click(screen.getByTestId('chat-cart-submit'));

        await waitFor(() => {
            expect(screen.getByTestId('chat-cart-error')).toBeInTheDocument();
        });

        const email = screen.getByLabelText('EA email');
        expect(email).toHaveAttribute('aria-invalid', 'true');
        expect(email).toHaveAttribute(
            'aria-describedby',
            screen.getByTestId('chat-cart-error').id,
        );
    });

    it('moves focus into the form when it expands', async () => {
        vi.stubGlobal('fetch', stubFetch(cartCreatedResponse));
        render(
            <ChatCartOffer
                offer={consoleOffer}
                locale="en"
                servicePrices={PRICES}
            />,
        );

        openForm();

        await waitFor(() => {
            expect(document.activeElement).toBe(
                screen.getByLabelText('EA email'),
            );
        });
    });

    it('asks for the current balance only on the fast console route', async () => {
        vi.stubGlobal('fetch', stubFetch(cartCreatedResponse));
        const { rerender } = render(
            <ChatCartOffer
                offer={consoleOffer}
                locale="en"
                servicePrices={PRICES}
            />,
        );

        openForm();
        expect(
            screen.queryByLabelText('Current coin balance'),
        ).not.toBeInTheDocument();

        rerender(
            <ChatCartOffer
                offer={fastOffer}
                locale="en"
                servicePrices={PRICES}
            />,
        );
        openForm();

        expect(
            screen.getByLabelText('Current coin balance'),
        ).toBeInTheDocument();
    });

    it('surfaces a rejected field instead of claiming the item was added', async () => {
        const rejected = () =>
            ({
                status: 422,
                json: async () => ({
                    message: 'invalid',
                    errors: { 'credentials.ea_password': ['wrong'] },
                }),
            }) as unknown as Response;

        vi.stubGlobal('fetch', stubFetch(rejected));
        render(
            <ChatCartOffer
                offer={consoleOffer}
                locale="en"
                servicePrices={PRICES}
            />,
        );

        openForm();
        fillCredentials();
        fireEvent.click(screen.getByTestId('chat-cart-submit'));

        await waitFor(() => {
            expect(screen.getByTestId('chat-cart-error')).toBeInTheDocument();
        });

        expect(screen.queryByTestId('chat-cart-added')).not.toBeInTheDocument();
    });

    it('offers no button at all when the store cannot price the selection', () => {
        vi.stubGlobal('fetch', stubFetch(cartCreatedResponse));
        render(
            <ChatCartOffer
                offer={consoleOffer}
                locale="en"
                servicePrices={{}}
            />,
        );

        // Committing to a purchase having seen no number is not a choice the
        // panel should offer.
        expect(screen.getByTestId('chat-cart-unpriced')).toBeInTheDocument();
        expect(screen.queryByTestId('chat-cart-price')).not.toBeInTheDocument();
        expect(screen.queryByTestId('chat-cart-start')).not.toBeInTheDocument();
    });

    it('reuses the idempotency key when a created item could not be read back', async () => {
        // A 201 whose body is truncated means the item exists. Minting a fresh
        // key on retry would add it a second time.
        let attempt = 0;
        const flaky = () => {
            attempt += 1;

            return attempt === 1
                ? ({
                      status: 201,
                      json: async () => {
                          throw new SyntaxError('truncated');
                      },
                  } as unknown as Response)
                : cartCreatedResponse();
        };

        vi.stubGlobal('fetch', stubFetch(flaky));
        render(
            <ChatCartOffer
                offer={consoleOffer}
                locale="en"
                servicePrices={PRICES}
            />,
        );

        openForm();
        fillCredentials();
        fireEvent.click(screen.getByTestId('chat-cart-submit'));

        await waitFor(() => {
            expect(screen.getByTestId('chat-cart-error')).toBeInTheDocument();
        });

        fireEvent.click(screen.getByTestId('chat-cart-submit'));

        await waitFor(() => {
            expect(screen.getByTestId('chat-cart-added')).toBeInTheDocument();
        });

        const keys = cartCalls().map(
            ([, init]) =>
                (init?.headers as Record<string, string>)['Idempotency-Key'],
        );

        expect(keys).toHaveLength(2);
        expect(keys[0]).toBe(keys[1]);
    });
});
