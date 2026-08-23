import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import type React from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { ChatMessageList } from '@/components/chat/chat-message-list';
import { formatMinorUnits } from '@/lib/money';
import type { ChatMessage, ChatServicePrices } from '@/types/chat';

vi.mock('@inertiajs/react', () => ({
    Link: ({
        href,
        children,
        ...props
    }: {
        href: string;
        children: React.ReactNode;
    }) => (
        <a href={href} {...props}>
            {children}
        </a>
    ),
}));

const card = {
    id: 'coins',
    title: 'شحن كوينز FC',
    subtitle: 'اختر منصتك والكمية',
    cta: 'اطلب الآن',
    url: '/#coins',
    image: '/images/store/coins/ut-coin-240.webp',
};

const sbcCard = {
    id: 'sbc',
    title: 'تحديات SBC',
    subtitle: 'تشكيلات وتحديات فورية',
    cta: 'اطلب الآن',
    url: '/sbc',
    image: '/images/store/services/sbc.webp',
};

function assistantMessage(
    metadata: unknown,
    extra: Partial<ChatMessage> = {},
): ChatMessage {
    return {
        publicId: 'assistant-message',
        senderType: 'assistant',
        messageType: 'text',
        content: 'رد المساعد',
        metadata: metadata as ChatMessage['metadata'],
        createdAt: '2026-08-23T10:00:00Z',
        ...extra,
    };
}

function renderList(
    messages: ChatMessage[],
    onCardNavigate?: () => void,
    servicePrices?: ChatServicePrices,
    locale: string = 'ar',
) {
    render(
        <ChatMessageList
            messages={messages}
            servicePrices={servicePrices}
            isLoading={false}
            isAssistantTyping={false}
            hasMore={false}
            isLoadingOlder={false}
            locale={locale}
            onLoadOlder={() => {}}
            onSelectSuggestion={() => {}}
            onRetry={() => {}}
            onCardNavigate={onCardNavigate}
        />,
    );
}

/**
 * Intl formats money with a non-breaking space. Testing Library normalizes
 * whitespace in the DOM but not in the expected string, so comparing the two
 * directly fails on strings that look identical. Compare normalized text.
 */
function priceText(): string {
    return (
        screen
            .getByTestId('chat-service-card-price')
            .textContent?.replace(/\s+/g, ' ')
            .trim() ?? ''
    );
}

function normalized(value: string): string {
    return value.replace(/\s+/g, ' ').trim();
}

beforeEach(() => {
    Element.prototype.scrollIntoView = vi.fn();
});

afterEach(cleanup);

describe('service cards in the message list', () => {
    it('renders a card under the assistant reply as a storefront link', () => {
        renderList([
            assistantMessage({ cards: { version: 'cards.v1', items: [card] } }),
        ]);

        const link = screen.getByTestId('chat-service-card');

        expect(link).toHaveAttribute('href', '/#coins');
        expect(screen.getByText('شحن كوينز FC')).toBeInTheDocument();
        expect(screen.getByText('اطلب الآن')).toBeInTheDocument();
        expect(link.querySelector('img')).toHaveAttribute(
            'src',
            '/images/store/coins/ut-coin-240.webp',
        );
    });

    it('shows the whole subtitle rather than truncating it', () => {
        renderList([
            assistantMessage({ cards: { version: 'cards.v1', items: [card] } }),
        ]);

        const subtitle = screen.getByText('اختر منصتك والكمية');

        expect(subtitle.className).not.toContain('truncate');
    });

    it('steps the sheet aside when a card is tapped on a phone', () => {
        const onCardNavigate = vi.fn();

        renderList(
            [
                assistantMessage({
                    cards: { version: 'cards.v1', items: [card] },
                }),
            ],
            onCardNavigate,
        );

        fireEvent.click(screen.getByTestId('chat-service-card'));

        expect(onCardNavigate).toHaveBeenCalledTimes(1);
    });

    it('renders no card while the reply is still streaming', () => {
        renderList([
            assistantMessage(
                { cards: { version: 'cards.v1', items: [card] } },
                { streamStatus: 'streaming' },
            ),
        ]);

        expect(screen.queryByTestId('chat-service-card')).toBeNull();
    });

    it('renders the reply normally when the payload is malformed', () => {
        renderList([
            assistantMessage({ cards: { version: 'cards.v9', items: [card] } }),
        ]);

        expect(screen.queryByTestId('chat-service-card')).toBeNull();
        expect(screen.getByText('رد المساعد')).toBeInTheDocument();
    });

    it('renders no card for a reply that carries none', () => {
        renderList([assistantMessage(null)]);

        expect(screen.queryByTestId('chat-service-card')).toBeNull();
    });

    it('renders the formatted per-100k starting price for coins when an entry exists', () => {
        const prices: ChatServicePrices = {
            coins: {
                amountMinor: 2500,
                currency: 'SAR',
                unit: 'per_100k',
            },
        };

        renderList(
            [
                assistantMessage({
                    cards: { version: 'cards.v1', items: [card] },
                }),
            ],
            undefined,
            prices,
            'ar',
        );

        expect(priceText()).toBe(
            normalized(`من ${formatMinorUnits(2500, 'SAR', 'ar')} لكل 100 ألف`),
        );
    });

    it('renders the starting price in English for coins with per-100k phrasing', () => {
        const prices: ChatServicePrices = {
            coins: {
                amountMinor: 2500,
                currency: 'SAR',
                unit: 'per_100k',
            },
        };

        renderList(
            [
                assistantMessage({
                    cards: { version: 'cards.v1', items: [card] },
                }),
            ],
            undefined,
            prices,
            'en',
        );

        expect(priceText()).toBe(
            normalized(`From ${formatMinorUnits(2500, 'SAR', 'en')} per 100k`),
        );
    });

    it('renders total starting price for non-coins services in Arabic and English', () => {
        const prices: ChatServicePrices = {
            sbc: {
                amountMinor: 4500,
                currency: 'SAR',
                unit: 'total',
            },
        };

        const { unmount } = render(
            <ChatMessageList
                messages={[
                    assistantMessage({
                        cards: { version: 'cards.v1', items: [sbcCard] },
                    }),
                ]}
                servicePrices={prices}
                isLoading={false}
                isAssistantTyping={false}
                hasMore={false}
                isLoadingOlder={false}
                locale="ar"
                onLoadOlder={() => {}}
                onSelectSuggestion={() => {}}
                onRetry={() => {}}
            />,
        );

        expect(priceText()).toBe(
            normalized(`يبدأ من ${formatMinorUnits(4500, 'SAR', 'ar')}`),
        );

        unmount();

        render(
            <ChatMessageList
                messages={[
                    assistantMessage({
                        cards: { version: 'cards.v1', items: [sbcCard] },
                    }),
                ]}
                servicePrices={prices}
                isLoading={false}
                isAssistantTyping={false}
                hasMore={false}
                isLoadingOlder={false}
                locale="en"
                onLoadOlder={() => {}}
                onSelectSuggestion={() => {}}
                onRetry={() => {}}
            />,
        );

        expect(priceText()).toBe(
            normalized(`From ${formatMinorUnits(4500, 'SAR', 'en')}`),
        );
    });

    it('renders no price line when no price entry exists for the card', () => {
        renderList(
            [
                assistantMessage({
                    cards: { version: 'cards.v1', items: [card] },
                }),
            ],
            undefined,
            {},
            'ar',
        );

        expect(screen.queryByText(/لكل 100 ألف/)).toBeNull();
        expect(screen.queryByText(/يبدأ من/)).toBeNull();
        expect(screen.queryByText(/From/)).toBeNull();
    });
});
