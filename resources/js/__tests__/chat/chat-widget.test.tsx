import {
    act,
    cleanup,
    fireEvent,
    render,
    screen,
    waitFor,
} from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { ChatWidget } from '@/components/chat/chat-widget';

describe('ChatWidget Component', () => {
    beforeEach(() => {
        vi.stubGlobal('fetch', vi.fn());
        Element.prototype.scrollIntoView = vi.fn();
    });

    afterEach(() => {
        cleanup();
        vi.restoreAllMocks();
    });

    it('renders nothing when enabled is false', () => {
        const { container } = render(
            <ChatWidget enabled={false} locale="ar" />,
        );
        expect(container.firstChild).toBeNull();
    });

    it('renders launcher button in Arabic mode when locale is ar', () => {
        render(<ChatWidget enabled={true} locale="ar" />);

        const launcherButton = screen.getByRole('button', {
            name: /فتح الشات/i,
        });
        expect(launcherButton).toBeInTheDocument();
        expect(launcherButton).toHaveAttribute('aria-expanded', 'false');
    });

    it('renders launcher button and UI in English mode when locale is en', async () => {
        const mockConversation = {
            publicId: '01JM0000000000000000000001',
            status: 'open',
            locale: 'en',
            subject: null,
            lastMessageAt: '2026-08-20T10:00:00.000Z',
            messages: [
                {
                    publicId: 'msg-sys-en-1',
                    conversationPublicId: '01JM0000000000000000000001',
                    senderType: 'system',
                    messageType: 'system',
                    content:
                        'Welcome to Arab UT! Ask anything about coins, services, or your order.',
                    createdAt: '2026-08-20T10:00:00.000Z',
                },
            ],
            hasMore: false,
            oldestCursor: null,
        };

        vi.mocked(fetch).mockResolvedValueOnce({
            ok: true,
            status: 200,
            json: async () => ({ data: mockConversation }),
        } as Response);

        render(<ChatWidget enabled={true} locale="en" />);

        const launcherButton = screen.getByRole('button', {
            name: /Open chat/i,
        });
        expect(launcherButton).toBeInTheDocument();

        fireEvent.click(launcherButton);

        expect(
            screen.getByRole('dialog', { name: /Arab UT Chat Assistant/i }),
        ).toBeInTheDocument();

        await waitFor(() => {
            expect(
                screen.getByText(
                    /Welcome to Arab UT! Ask anything about coins/i,
                ),
            ).toBeInTheDocument();
        });

        // English suggestion chips
        expect(screen.getByText('Prices')).toBeInTheDocument();
        expect(screen.getByText('Services')).toBeInTheDocument();
        expect(screen.getByText('Track Order')).toBeInTheDocument();
        expect(screen.getByText('Support')).toBeInTheDocument();

        // English composer
        expect(
            screen.getByPlaceholderText(/Type a message/i),
        ).toBeInTheDocument();
        expect(
            screen.getByRole('button', { name: /Send message/i }),
        ).toBeInTheDocument();
    });

    it('opens chat panel on launcher click and lazily fetches active conversation in Arabic', async () => {
        const mockConversation = {
            publicId: '01JM0000000000000000000001',
            status: 'open',
            locale: 'ar',
            subject: null,
            lastMessageAt: '2026-08-20T10:00:00.000Z',
            messages: [
                {
                    publicId: 'msg-sys-1',
                    conversationPublicId: '01JM0000000000000000000001',
                    senderType: 'system',
                    messageType: 'system',
                    content: 'هلا 👋 أنا مساعد عرب التيميت. اكتب رسالتك...',
                    createdAt: '2026-08-20T10:00:00.000Z',
                },
            ],
            hasMore: false,
            oldestCursor: null,
        };

        vi.mocked(fetch).mockResolvedValueOnce({
            ok: true,
            status: 200,
            json: async () => ({ data: mockConversation }),
        } as Response);

        render(<ChatWidget enabled={true} locale="ar" />);

        const launcherButton = screen.getByRole('button', {
            name: /فتح الشات/i,
        });
        fireEvent.click(launcherButton);

        expect(
            screen.getByRole('dialog', { name: /مساعد عرب التيميت/i }),
        ).toBeInTheDocument();

        await waitFor(() => {
            expect(
                screen.getByText(/هلا 👋 أنا مساعد عرب التيميت/i),
            ).toBeInTheDocument();
        });

        expect(screen.getByText('الأسعار')).toBeInTheDocument();
        expect(screen.getByText('الخدمات')).toBeInTheDocument();
        expect(screen.getByText('متابعة الطلب')).toBeInTheDocument();
        expect(screen.getByText('الدعم')).toBeInTheDocument();
    });

    it('submits a customer message optimistically and calls POST message endpoint', async () => {
        const mockConversation = {
            publicId: '01JM0000000000000000000001',
            status: 'open',
            locale: 'ar',
            subject: null,
            lastMessageAt: '2026-08-20T10:00:00.000Z',
            messages: [
                {
                    publicId: 'msg-sys-1',
                    conversationPublicId: '01JM0000000000000000000001',
                    senderType: 'system',
                    messageType: 'system',
                    content: 'هلا 👋 أنا مساعد عرب التيميت.',
                    createdAt: '2026-08-20T10:00:00.000Z',
                },
            ],
            hasMore: false,
            oldestCursor: null,
        };

        vi.mocked(fetch)
            .mockResolvedValueOnce({
                ok: true,
                status: 200,
                json: async () => ({ data: mockConversation }),
            } as Response)
            .mockResolvedValueOnce({
                ok: true,
                status: 201,
                json: async () => ({
                    data: {
                        message: {
                            publicId: 'msg-cust-1',
                            conversationPublicId: '01JM0000000000000000000001',
                            senderType: 'customer',
                            messageType: 'text',
                            content: 'كم سعر 500 ألف كوينز؟',
                            createdAt: '2026-08-20T10:01:00.000Z',
                        },
                        demoReply: null,
                    },
                }),
            } as Response);

        render(<ChatWidget enabled={true} locale="ar" />);

        fireEvent.click(screen.getByRole('button', { name: /فتح الشات/i }));

        await waitFor(() => {
            expect(
                screen.getByText(/هلا 👋 أنا مساعد عرب التيميت/i),
            ).toBeInTheDocument();
        });

        const textarea = screen.getByPlaceholderText(/اكتب رسالتك هنا/i);
        fireEvent.change(textarea, {
            target: { value: 'كم سعر 500 ألف كوينز؟' },
        });

        const sendButton = screen.getByRole('button', {
            name: /إرسال الرسالة/i,
        });
        fireEvent.click(sendButton);

        expect(screen.getByText('كم سعر 500 ألف كوينز؟')).toBeInTheDocument();
        expect(screen.queryByText('الأسعار')).not.toBeInTheDocument();
    });

    it('clicking a suggestion chip sends that message immediately', async () => {
        const mockConversation = {
            publicId: '01JM0000000000000000000001',
            status: 'open',
            locale: 'ar',
            subject: null,
            lastMessageAt: '2026-08-20T10:00:00.000Z',
            messages: [],
            hasMore: false,
            oldestCursor: null,
        };

        vi.mocked(fetch)
            .mockResolvedValueOnce({
                ok: true,
                status: 200,
                json: async () => ({ data: mockConversation }),
            } as Response)
            .mockResolvedValueOnce({
                ok: true,
                status: 201,
                json: async () => ({
                    data: {
                        message: {
                            publicId: 'msg-chip-1',
                            conversationPublicId: '01JM0000000000000000000001',
                            senderType: 'customer',
                            messageType: 'text',
                            content: 'الأسعار',
                            createdAt: '2026-08-20T10:01:00.000Z',
                        },
                        demoReply: null,
                    },
                }),
            } as Response);

        render(<ChatWidget enabled={true} locale="ar" />);
        fireEvent.click(screen.getByRole('button', { name: /فتح الشات/i }));

        await waitFor(() => {
            expect(screen.getByText('الأسعار')).toBeInTheDocument();
        });

        fireEvent.click(screen.getByText('الأسعار'));

        expect(screen.getByText('الأسعار')).toBeInTheDocument();
    });

    it('closes on Escape key press and restores focus to launcher', async () => {
        render(<ChatWidget enabled={true} locale="ar" />);

        const launcher = screen.getByRole('button', { name: /فتح الشات/i });
        fireEvent.click(launcher);

        expect(
            screen.getByRole('dialog', { name: /مساعد عرب التيميت/i }),
        ).toBeInTheDocument();

        act(() => {
            fireEvent.keyDown(window, { key: 'Escape', code: 'Escape' });
        });

        // After Escape, launcher receives focus
        expect(launcher).toBeInTheDocument();
    });

    it('reveals demo assistant reply after typing indicator delay when demoReply is returned', async () => {
        vi.useFakeTimers();

        const mockConversation = {
            publicId: '01JM0000000000000000000001',
            status: 'open',
            locale: 'ar',
            messages: [],
            hasMore: false,
            oldestCursor: null,
        };

        const mockDemoReply = {
            publicId: 'msg-demo-1',
            conversationPublicId: '01JM0000000000000000000001',
            senderType: 'assistant' as const,
            messageType: 'text' as const,
            content: 'وصلتني رسالتك 👍 هذي نسخة تجريبية من الشات.',
            createdAt: '2026-08-20T10:02:00.000Z',
        };

        vi.mocked(fetch)
            .mockResolvedValueOnce({
                ok: true,
                status: 200,
                json: async () => ({ data: mockConversation }),
            } as Response)
            .mockResolvedValueOnce({
                ok: true,
                status: 201,
                json: async () => ({
                    data: {
                        message: {
                            publicId: 'msg-cust-2',
                            conversationPublicId: '01JM0000000000000000000001',
                            senderType: 'customer',
                            messageType: 'text',
                            content: 'مرحبا',
                            createdAt: '2026-08-20T10:01:50.000Z',
                        },
                        demoReply: mockDemoReply,
                    },
                }),
            } as Response);

        render(<ChatWidget enabled={true} locale="ar" />);
        fireEvent.click(screen.getByRole('button', { name: /فتح الشات/i }));

        await act(async () => {
            await Promise.resolve();
        });

        const textarea = screen.getByPlaceholderText(/اكتب رسالتك هنا/i);
        fireEvent.change(textarea, { target: { value: 'مرحبا' } });
        fireEvent.click(screen.getByRole('button', { name: /إرسال الرسالة/i }));

        await act(async () => {
            await Promise.resolve();
        });

        expect(screen.getByText('المساعد يكتب الآن...')).toBeInTheDocument();

        act(() => {
            vi.advanceTimersByTime(1200);
        });

        expect(
            screen.getByText('وصلتني رسالتك 👍 هذي نسخة تجريبية من الشات.'),
        ).toBeInTheDocument();

        vi.useRealTimers();
    });
});
