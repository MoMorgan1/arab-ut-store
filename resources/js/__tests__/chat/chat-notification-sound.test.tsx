import {
    act,
    cleanup,
    fireEvent,
    render,
    screen,
} from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { ChatWidget } from '@/components/chat/chat-widget';

vi.mock('@/lib/chat-sound', () => ({
    isChatSoundEnabled: vi.fn(() => true),
    setChatSoundEnabled: vi.fn(),
    playChatNotification: vi.fn(() => true),
}));

import {
    isChatSoundEnabled,
    playChatNotification,
    setChatSoundEnabled,
} from '@/lib/chat-sound';

const assistantHistory = {
    publicId: 'msg-history-1',
    conversationPublicId: 'conv-sound',
    senderType: 'assistant',
    messageType: 'text',
    content: 'Earlier reply',
    createdAt: '2026-08-22T10:00:00.000Z',
};

function mockConversation(messages: unknown[]) {
    vi.mocked(fetch).mockImplementation(async (url) => {
        const path = String(url);

        if (path.includes('/messages')) {
            return {
                ok: true,
                status: 201,
                json: async () => ({
                    data: {
                        message: {
                            publicId: 'msg-c-1',
                            conversationPublicId: 'conv-sound',
                            clientMessageId: 'c-1',
                            senderType: 'customer',
                            messageType: 'text',
                            content: 'Hi',
                            createdAt: new Date().toISOString(),
                        },
                        demoReply: {
                            publicId: 'msg-demo-1',
                            conversationPublicId: 'conv-sound',
                            senderType: 'assistant',
                            messageType: 'text',
                            content: 'Demo reply',
                            createdAt: new Date().toISOString(),
                        },
                    },
                }),
            } as Response;
        }

        return {
            ok: true,
            status: 200,
            json: async () => ({
                data: {
                    publicId: 'conv-sound',
                    status: 'open',
                    locale: 'en',
                    assistantMode: 'demo',
                    messages,
                    hasMore: false,
                    oldestCursor: null,
                },
            }),
        } as Response;
    });
}

describe('chat notification sound', () => {
    beforeEach(() => {
        vi.stubGlobal('fetch', vi.fn());
        Element.prototype.scrollIntoView = vi.fn();
        vi.mocked(playChatNotification).mockClear();
        vi.mocked(setChatSoundEnabled).mockClear();
        vi.mocked(isChatSoundEnabled).mockReturnValue(true);
    });

    afterEach(() => {
        cleanup();
        vi.useRealTimers();
        vi.restoreAllMocks();
        vi.unstubAllGlobals();
    });

    it('does not chime for history loaded on open but chimes for a new assistant reply', async () => {
        vi.useFakeTimers();
        mockConversation([assistantHistory]);
        render(<ChatWidget initialView="chat" enabled={true} locale="en" />);
        fireEvent.click(screen.getByRole('button', { name: /Open chat/i }));
        await act(async () => {
            await vi.advanceTimersByTimeAsync(50);
        });

        expect(screen.getByText('Earlier reply')).toBeInTheDocument();
        expect(playChatNotification).not.toHaveBeenCalled();

        fireEvent.change(screen.getByRole('textbox'), {
            target: { value: 'Hi' },
        });
        fireEvent.click(screen.getByRole('button', { name: 'Send message' }));
        // Demo reply lands after the typing delay.
        await act(async () => {
            await vi.advanceTimersByTimeAsync(1500);
        });

        expect(screen.getByText('Demo reply')).toBeInTheDocument();
        expect(playChatNotification).toHaveBeenCalledTimes(1);
    });

    it('mutes when toggled and persists the preference', async () => {
        vi.useFakeTimers();
        mockConversation([]);
        render(<ChatWidget initialView="chat" enabled={true} locale="en" />);
        fireEvent.click(screen.getByRole('button', { name: /Open chat/i }));
        await act(async () => {
            await vi.advanceTimersByTimeAsync(50);
        });

        const toggle = screen.getByRole('button', {
            name: 'Mute notification sound',
        });
        expect(toggle).toHaveAttribute('aria-pressed', 'true');
        fireEvent.click(toggle);
        expect(setChatSoundEnabled).toHaveBeenCalledWith(false);
        expect(
            screen.getByRole('button', { name: 'Unmute notification sound' }),
        ).toHaveAttribute('aria-pressed', 'false');

        fireEvent.change(screen.getByRole('textbox'), {
            target: { value: 'Hi' },
        });
        fireEvent.click(screen.getByRole('button', { name: 'Send message' }));
        await act(async () => {
            await vi.advanceTimersByTimeAsync(1500);
        });

        expect(screen.getByText('Demo reply')).toBeInTheDocument();
        expect(playChatNotification).not.toHaveBeenCalled();
    });
});
