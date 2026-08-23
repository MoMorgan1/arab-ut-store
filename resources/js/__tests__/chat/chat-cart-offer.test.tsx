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

function quoteResponse() {
    return {
        ok: true,
        status: 200,
        json: async () => ({
            data: { displayTotal: { amountMinor: 910, currency: 'SAR' } },
        }),
    } as unknown as Response;
}

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

        return url.includes('/coins/quote') ? quoteResponse() : cartResponse();
    });
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

    it('shows the live price rather than one stored in the message', async () => {
        vi.stubGlobal('fetch', stubFetch(cartCreatedResponse));
        render(<ChatCartOffer offer={consoleOffer} locale="en" />);

        await waitFor(() => {
            expect(screen.getByTestId('chat-cart-price')).toBeInTheDocument();
        });

        expect(screen.getByTestId('chat-cart-price').textContent).toContain(
            '9.10',
        );
        expect(calls[0]?.[0]).toContain('/coins/quote');
        expect(calls[0]?.[0]).toContain('delivery=normal');
    });

    it('posts the credentials to the cart endpoint, never as a message', async () => {
        vi.stubGlobal('fetch', stubFetch(cartCreatedResponse));
        render(<ChatCartOffer offer={consoleOffer} locale="en" />);

        fireEvent.click(screen.getByTestId('chat-cart-start'));
        fillCredentials();
        fireEvent.click(screen.getByTestId('chat-cart-submit'));

        await waitFor(() => {
            expect(screen.getByTestId('chat-cart-added')).toBeInTheDocument();
        });

        const cartCall = calls.find(([url]) =>
            url.includes('/cart/items/coins'),
        );
        expect(cartCall).toBeDefined();

        const [, init] = cartCall ?? [];
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

    it('clears the password from the panel once the item is in the cart', async () => {
        vi.stubGlobal('fetch', stubFetch(cartCreatedResponse));
        render(<ChatCartOffer offer={consoleOffer} locale="en" />);

        fireEvent.click(screen.getByTestId('chat-cart-start'));
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
        render(<ChatCartOffer offer={consoleOffer} locale="en" />);

        fireEvent.click(screen.getByTestId('chat-cart-start'));
        fireEvent.change(screen.getByLabelText('EA email'), {
            target: { value: 'not-an-email' },
        });
        fireEvent.click(screen.getByTestId('chat-cart-submit'));

        await waitFor(() => {
            expect(screen.getByTestId('chat-cart-error')).toBeInTheDocument();
        });

        expect(
            calls.filter(([url]) => url.includes('/cart/items/coins')),
        ).toHaveLength(0);
    });

    it('asks for the current balance only on the fast console route', async () => {
        vi.stubGlobal('fetch', stubFetch(cartCreatedResponse));
        const { rerender } = render(
            <ChatCartOffer offer={consoleOffer} locale="en" />,
        );

        fireEvent.click(screen.getByTestId('chat-cart-start'));
        expect(
            screen.queryByLabelText('Current coin balance'),
        ).not.toBeInTheDocument();

        rerender(<ChatCartOffer offer={fastOffer} locale="en" />);
        fireEvent.click(screen.getByTestId('chat-cart-start'));

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
        render(<ChatCartOffer offer={consoleOffer} locale="en" />);

        fireEvent.click(screen.getByTestId('chat-cart-start'));
        fillCredentials();
        fireEvent.click(screen.getByTestId('chat-cart-submit'));

        await waitFor(() => {
            expect(screen.getByTestId('chat-cart-error')).toBeInTheDocument();
        });

        expect(screen.queryByTestId('chat-cart-added')).not.toBeInTheDocument();
    });

    it('renders no price when the store cannot quote one', async () => {
        const unavailable = vi.fn(async (input: RequestInfo | URL) => {
            calls.push([String(input), undefined]);

            return {
                status: 503,
                json: async () => ({}),
            } as unknown as Response;
        });

        vi.stubGlobal('fetch', unavailable);
        render(<ChatCartOffer offer={consoleOffer} locale="en" />);

        await waitFor(() => {
            expect(unavailable).toHaveBeenCalled();
        });

        expect(screen.queryByTestId('chat-cart-price')).not.toBeInTheDocument();
        expect(screen.getByTestId('chat-cart-start')).toBeInTheDocument();
    });
});
