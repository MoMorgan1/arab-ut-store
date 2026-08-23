import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import type React from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { ChatMessageList } from '@/components/chat/chat-message-list';
import type { ChatMessage } from '@/types/chat';

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

function renderList(messages: ChatMessage[], onCardNavigate?: () => void) {
    render(
        <ChatMessageList
            messages={messages}
            isLoading={false}
            isAssistantTyping={false}
            hasMore={false}
            isLoadingOlder={false}
            locale="ar"
            onLoadOlder={() => {}}
            onSelectSuggestion={() => {}}
            onRetry={() => {}}
            onCardNavigate={onCardNavigate}
        />,
    );
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
});
