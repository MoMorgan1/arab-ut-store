import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { ChatHome } from '@/components/chat/chat-home';
import type { ChatConversationSummary } from '@/types/chat';

describe('ChatHistory UI', () => {
    afterEach(() => {
        cleanup();
    });

    it('renders previous conversations list with subject, date, and ticket badge for authenticated customer', () => {
        const conversations: ChatConversationSummary[] = [
            {
                publicId: 'conv-1',
                subject: 'Help with PlayStation coins order',
                lastMessageAt: '2026-08-24T10:00:00Z',
                status: 'open',
                ticketNumber: 'TKT-4F9A2C',
            },
            {
                publicId: 'conv-2',
                subject: 'Inquiry about FUT Champions boost',
                lastMessageAt: '2026-08-23T15:30:00Z',
                status: 'closed',
            },
        ];

        const onSelectConversation = vi.fn();

        render(
            <ChatHome
                locale="ar"
                hasConversation={true}
                lastMessage={null}
                conversations={conversations}
                onStart={vi.fn()}
                onContinue={vi.fn()}
                onSelectTopic={vi.fn()}
                onSelectConversation={onSelectConversation}
                onClose={vi.fn()}
            />,
        );

        expect(screen.getByText('محادثاتك السابقة')).toBeInTheDocument();
        expect(
            screen.getByText('Help with PlayStation coins order'),
        ).toBeInTheDocument();
        expect(screen.getByText('TKT-4F9A2C')).toBeInTheDocument();
        expect(
            screen.getByText('Inquiry about FUT Champions boost'),
        ).toBeInTheDocument();

        // Tapping row opens that thread
        const firstRow = screen
            .getByText('Help with PlayStation coins order')
            .closest('button');
        expect(firstRow).not.toBeNull();
        fireEvent.click(firstRow!);
        expect(onSelectConversation).toHaveBeenCalledWith('conv-1');
    });

    it('renders in English with LTR formatting', () => {
        const conversations: ChatConversationSummary[] = [
            {
                publicId: 'conv-1',
                subject: 'Order tracking request',
                lastMessageAt: '2026-08-24T10:00:00Z',
                status: 'open',
            },
        ];

        render(
            <ChatHome
                locale="en"
                hasConversation={false}
                lastMessage={null}
                conversations={conversations}
                onStart={vi.fn()}
                onContinue={vi.fn()}
                onSelectTopic={vi.fn()}
                onClose={vi.fn()}
            />,
        );

        expect(screen.getByText('Previous conversations')).toBeInTheDocument();
        expect(screen.getByText('Order tracking request')).toBeInTheDocument();
    });

    it('does NOT render previous conversations section for guests (empty conversation array)', () => {
        render(
            <ChatHome
                locale="ar"
                hasConversation={false}
                lastMessage={null}
                conversations={[]}
                onStart={vi.fn()}
                onContinue={vi.fn()}
                onSelectTopic={vi.fn()}
                onClose={vi.fn()}
            />,
        );

        expect(screen.queryByText('محادثاتك السابقة')).not.toBeInTheDocument();
        expect(
            screen.queryByText('Previous conversations'),
        ).not.toBeInTheDocument();
    });
});
