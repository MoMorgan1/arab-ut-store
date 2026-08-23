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

const choices = {
    version: 'choices.v1',
    prompt: 'على أي منصة؟',
    items: [
        {
            id: 'coins-platform:playstation',
            label: 'بلايستيشن',
            message: 'بلايستيشن',
        },
        { id: 'coins-platform:pc', label: 'كمبيوتر PC', message: 'بي سي' },
    ],
};

function assistantMessage(
    publicId: string,
    metadata: unknown = null,
): ChatMessage {
    return {
        publicId,
        senderType: 'assistant',
        messageType: 'text',
        content: 'رد المساعد',
        metadata: metadata as ChatMessage['metadata'],
        createdAt: '2026-08-23T10:00:00Z',
    };
}

function renderList(messages: ChatMessage[], onChoose?: (m: string) => void) {
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
            onChoose={onChoose}
        />,
    );
}

beforeEach(() => {
    // jsdom has no layout, so the list's scroll anchoring needs a stub.
    Element.prototype.scrollIntoView = vi.fn();
});

afterEach(cleanup);

describe('choice chips in the transcript', () => {
    it('shows the question and its options', () => {
        renderList([assistantMessage('m1', { choices })], () => {});

        expect(screen.getByTestId('chat-choices')).toHaveTextContent(
            'على أي منصة؟',
        );
        expect(screen.getAllByTestId('chat-choice')).toHaveLength(2);
    });

    it('sends the chip text as an ordinary customer message', () => {
        const onChoose = vi.fn();
        renderList([assistantMessage('m1', { choices })], onChoose);

        const chip = screen.getAllByTestId('chat-choice')[0];
        expect(chip).toBeEnabled();
        fireEvent.click(chip);

        expect(onChoose).toHaveBeenCalledWith('بلايستيشن');
    });

    it('only the newest message may still be answered', () => {
        // An older question has already been answered; re-tapping it would send
        // a choice that no longer matches the conversation.
        renderList(
            [
                assistantMessage('older', { choices }),
                assistantMessage('newest'),
            ],
            () => {},
        );

        expect(screen.queryByTestId('chat-choices')).toBeNull();
    });

    it('renders nothing when the message carries no chips', () => {
        renderList([assistantMessage('m1')], () => {});

        expect(screen.queryByTestId('chat-choices')).toBeNull();
    });
});
