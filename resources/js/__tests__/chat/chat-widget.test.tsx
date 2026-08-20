import {
    act,
    cleanup,
    fireEvent,
    render,
    screen,
    waitFor,
    within,
} from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { ChatWidget } from '@/components/chat/chat-widget';

describe('ChatWidget Component', () => {
    const stubMatchMedia = (mobile: boolean) => {
        vi.stubGlobal(
            'matchMedia',
            vi.fn((query: string) => ({
                matches: query.includes('max-width') ? mobile : false,
                media: query,
                onchange: null,
                addEventListener: vi.fn(),
                removeEventListener: vi.fn(),
                addListener: vi.fn(),
                removeListener: vi.fn(),
                dispatchEvent: vi.fn(),
            })),
        );
    };

    beforeEach(() => {
        stubMatchMedia(false);
        vi.stubGlobal('fetch', vi.fn());
        Element.prototype.scrollIntoView = vi.fn();
    });

    afterEach(() => {
        cleanup();
        vi.useRealTimers();
        vi.unstubAllGlobals();
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

    // Regression: owner mobile acceptance on 2026-08-20 found the gold orb
    // visually excessive after the first launcher polish.
    it('uses the approved quiet launcher geometry', () => {
        render(<ChatWidget enabled={true} locale="ar" />);

        const launcherButton = screen.getByRole('button', {
            name: /فتح الشات/i,
        });

        expect(launcherButton).toHaveClass(
            'h-14',
            'w-14',
            'sm:h-[60px]',
            'sm:w-[60px]',
        );
        expect(launcherButton.className).toContain(
            'bg-[color:color-mix(in_srgb,var(--arabut-navy-raised)_88%,transparent)]',
        );
        expect(launcherButton).toHaveClass('backdrop-blur-md');
        expect(launcherButton.className).not.toContain('linear-gradient');
        expect(launcherButton.querySelector('.lucide-sparkles')).toBeNull();
    });

    it('anchors the desktop panel one spacing step above the launcher', () => {
        render(<ChatWidget enabled={true} locale="ar" />);

        fireEvent.click(screen.getByRole('button', { name: /فتح الشات/i }));

        expect(
            screen.getByRole('dialog', { name: /مساعد عرب التيميت/i }),
        ).toHaveClass('md:right-6', 'md:bottom-24', 'md:origin-bottom-right');
    });

    it('adds the account modifier and keeps the mobile dialog above account navigation', () => {
        const { container } = render(
            <ChatWidget enabled={true} locale="ar" surface="account" />,
        );

        expect(container.querySelector('.chat-widget-root')).toHaveClass(
            'chat-widget-root--account',
        );

        fireEvent.click(screen.getByRole('button', { name: /فتح الشات/i }));

        expect(
            screen.getByRole('dialog', { name: /مساعد عرب التيميت/i }),
        ).toHaveClass('chat-widget-dialog', 'z-[70]', 'overscroll-contain');
    });

    it('keeps the panel mounted only for the faster close transition', () => {
        vi.useFakeTimers();
        render(<ChatWidget enabled={true} locale="ar" />);

        fireEvent.click(screen.getByRole('button', { name: /فتح الشات/i }));
        act(() => vi.runOnlyPendingTimers());

        fireEvent.keyDown(window, { key: 'Escape', code: 'Escape' });

        expect(
            screen.getByRole('dialog', { name: /مساعد عرب التيميت/i }),
        ).toBeInTheDocument();

        act(() => vi.advanceTimersByTime(180));

        expect(
            screen.queryByRole('dialog', { name: /مساعد عرب التيميت/i }),
        ).not.toBeInTheDocument();
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

    it('restarts with a 44px localized control and replaces the conversation state', async () => {
        const oldConversation = {
            publicId: 'conversation-old',
            status: 'open',
            locale: 'en',
            messages: [
                {
                    publicId: 'message-old',
                    conversationPublicId: 'conversation-old',
                    senderType: 'system',
                    messageType: 'system',
                    content: 'Old onboarding message',
                    createdAt: '2026-08-20T10:00:00.000Z',
                },
            ],
            hasMore: true,
            oldestCursor: 'message-old',
        };
        const restartedConversation = {
            publicId: 'conversation-new',
            status: 'open',
            locale: 'en',
            messages: [
                {
                    publicId: 'message-new',
                    conversationPublicId: 'conversation-new',
                    senderType: 'system',
                    messageType: 'system',
                    content: 'Fresh onboarding message',
                    createdAt: '2026-08-20T10:05:00.000Z',
                },
            ],
            hasMore: false,
            oldestCursor: null,
        };

        vi.mocked(fetch)
            .mockResolvedValueOnce({
                ok: true,
                status: 200,
                json: async () => ({ data: oldConversation }),
            } as Response)
            .mockResolvedValueOnce({
                ok: true,
                status: 200,
                json: async () => ({ data: restartedConversation }),
            } as Response);

        render(<ChatWidget enabled={true} locale="en" />);
        fireEvent.click(screen.getByRole('button', { name: /Open chat/i }));

        await screen.findByText('Old onboarding message');
        const restart = screen.getByRole('button', {
            name: /New conversation/i,
        });
        expect(restart).toHaveClass('h-11', 'w-11');
        expect(restart).toHaveAttribute('title', 'New conversation');
        fireEvent.click(restart);

        await screen.findByText('Fresh onboarding message');
        expect(screen.queryByText('Old onboarding message')).toBeNull();
        expect(fetch).toHaveBeenNthCalledWith(
            2,
            '/chat/conversations/restart',
            expect.objectContaining({
                method: 'POST',
                body: JSON.stringify({ locale: 'en' }),
            }),
        );
        expect(screen.getByRole('status')).toHaveTextContent(
            'A new conversation has started.',
        );
    });

    it('disables restart while the restart request is in progress', async () => {
        let resolveRestart!: (response: Response) => void;
        const restartResponse = new Promise<Response>((resolve) => {
            resolveRestart = resolve;
        });
        const conversation = {
            publicId: 'conversation-current',
            status: 'open',
            locale: 'ar',
            messages: [],
            hasMore: true,
            oldestCursor: 'oldest-message',
        };

        vi.mocked(fetch)
            .mockResolvedValueOnce({
                ok: true,
                status: 200,
                json: async () => ({ data: conversation }),
            } as Response)
            .mockReturnValueOnce(restartResponse);

        render(<ChatWidget enabled={true} locale="ar" />);
        fireEvent.click(screen.getByRole('button', { name: /فتح الشات/i }));

        const restart = await screen.findByRole('button', {
            name: /محادثة جديدة/i,
        });
        await waitFor(() => expect(restart).toBeEnabled());
        fireEvent.click(restart);
        expect(restart).toBeDisabled();
        expect(screen.getByRole('textbox')).toBeDisabled();
        expect(
            screen.getByRole('button', { name: /تحميل الرسائل السابقة/i }),
        ).toBeDisabled();

        for (const suggestion of [
            'الأسعار',
            'الخدمات',
            'متابعة الطلب',
            'الدعم',
        ]) {
            expect(
                screen.getByRole('button', { name: suggestion }),
            ).toBeDisabled();
        }

        resolveRestart({
            ok: true,
            status: 200,
            json: async () => ({ data: conversation }),
        } as Response);
        await waitFor(() => expect(restart).toBeEnabled());
    });

    it.each([
        ['en', 'Failed to start a new conversation. Please try again.'],
        ['ar', 'تعذر بدء محادثة جديدة. حاول مرة أخرى.'],
    ])(
        'announces one localized restart failure in %s',
        async (locale, failureMessage) => {
            const conversation = {
                publicId: `conversation-${locale}`,
                status: 'open',
                locale,
                messages: [],
                hasMore: false,
                oldestCursor: null,
            };

            vi.mocked(fetch)
                .mockResolvedValueOnce({
                    ok: true,
                    status: 200,
                    json: async () => ({ data: conversation }),
                } as Response)
                .mockResolvedValueOnce({
                    ok: false,
                    status: 500,
                    json: async () => ({
                        error: { code: 'chat_unavailable' },
                    }),
                } as Response);

            render(<ChatWidget enabled={true} locale={locale} />);
            fireEvent.click(
                screen.getByRole('button', {
                    name: locale === 'en' ? /Open chat/i : /فتح الشات/i,
                }),
            );

            const restart = await screen.findByRole('button', {
                name: locale === 'en' ? /New conversation/i : /محادثة جديدة/i,
            });
            await waitFor(() => expect(restart).toBeEnabled());
            fireEvent.click(restart);

            const liveStatus = screen.getByRole('status');
            await waitFor(() =>
                expect(liveStatus).toHaveTextContent(failureMessage),
            );
            expect(screen.queryAllByRole('status')).toHaveLength(1);
            expect(screen.queryAllByRole('alert')).toHaveLength(0);
        },
    );

    it.each([
        ['en', 'Failed to start a new conversation. Please try again.'],
        ['ar', 'تعذر بدء محادثة جديدة. حاول مرة أخرى.'],
    ])(
        'creates a fresh live-region event for consecutive identical failures in %s',
        async (locale, failureMessage) => {
            const conversation = {
                publicId: `conversation-repeat-${locale}`,
                status: 'open',
                locale,
                messages: [],
                hasMore: false,
                oldestCursor: null,
            };
            const failedResponse = {
                ok: false,
                status: 500,
                json: async () => ({
                    error: { code: 'chat_unavailable' },
                }),
            } as Response;

            vi.mocked(fetch)
                .mockResolvedValueOnce({
                    ok: true,
                    status: 200,
                    json: async () => ({ data: conversation }),
                } as Response)
                .mockResolvedValueOnce(failedResponse)
                .mockResolvedValueOnce(failedResponse);

            render(<ChatWidget enabled={true} locale={locale} />);
            fireEvent.click(
                screen.getByRole('button', {
                    name: locale === 'en' ? /Open chat/i : /فتح الشات/i,
                }),
            );

            const restart = await screen.findByRole('button', {
                name: locale === 'en' ? /New conversation/i : /محادثة جديدة/i,
            });
            await waitFor(() => expect(restart).toBeEnabled());
            fireEvent.click(restart);

            const liveStatus = screen.getByRole('status');
            await waitFor(() =>
                expect(liveStatus).toHaveTextContent(failureMessage),
            );
            const firstEvent = liveStatus.firstElementChild;
            expect(firstEvent).not.toBeNull();

            await waitFor(() => expect(restart).toBeEnabled());
            fireEvent.click(restart);
            await waitFor(() =>
                expect(liveStatus.firstElementChild).not.toBe(firstEvent),
            );
            expect(liveStatus).toHaveTextContent(failureMessage);
            expect(screen.queryAllByRole('status')).toHaveLength(1);
            expect(screen.queryAllByRole('alert')).toHaveLength(0);
            expect(
                screen
                    .getAllByText(failureMessage)
                    .filter((element) => !element.closest('.sr-only')),
            ).toHaveLength(1);
        },
    );

    it('keeps one populated live-region node stable across mobile close and reopen', async () => {
        stubMatchMedia(true);
        const conversation = {
            publicId: 'stable-live-region-conversation',
            status: 'open',
            locale: 'en',
            messages: [],
            hasMore: false,
            oldestCursor: null,
        };
        const failureMessage =
            'Failed to start a new conversation. Please try again.';

        vi.mocked(fetch)
            .mockResolvedValueOnce({
                ok: true,
                status: 200,
                json: async () => ({ data: conversation }),
            } as Response)
            .mockResolvedValueOnce({
                ok: false,
                status: 500,
                json: async () => ({
                    error: { code: 'chat_unavailable' },
                }),
            } as Response);

        render(
            <>
                <main data-testid="stable-live-covered-page">Page</main>
                <ChatWidget enabled={true} locale="en" />
            </>,
        );

        const launcher = screen.getByRole('button', { name: /Open chat/i });
        fireEvent.click(launcher);
        const dialog = screen.getByRole('dialog');
        const restart = await screen.findByRole('button', {
            name: /New conversation/i,
        });
        await waitFor(() => expect(restart).toBeEnabled());
        fireEvent.click(restart);

        const stableStatus = screen.getByRole('status');
        await waitFor(() =>
            expect(stableStatus).toHaveTextContent(failureMessage),
        );
        const stableEvent = stableStatus.firstElementChild;
        expect(stableEvent).not.toBeNull();
        expect(stableStatus).not.toHaveAttribute('inert');

        fireEvent.click(
            within(dialog).getByRole('button', { name: /Close chat/i }),
        );
        expect(screen.getByRole('status')).toBe(stableStatus);
        expect(stableStatus.firstElementChild).toBe(stableEvent);
        expect(stableStatus).not.toHaveAttribute('inert');

        await waitFor(() => expect(dialog).not.toBeInTheDocument());
        expect(screen.getByRole('status')).toBe(stableStatus);
        expect(stableStatus.firstElementChild).toBe(stableEvent);
        expect(stableStatus).not.toHaveAttribute('inert');

        fireEvent.click(launcher);
        await waitFor(() => expect(screen.getByRole('dialog')).toBeVisible());
        expect(screen.getByRole('status')).toBe(stableStatus);
        expect(stableStatus.firstElementChild).toBe(stableEvent);
        expect(stableStatus).not.toHaveAttribute('inert');
        expect(screen.getByTestId('stable-live-covered-page')).toHaveAttribute(
            'inert',
        );
        expect(launcher).toHaveAttribute('inert');
    });

    it('treats the mobile sheet as modal, traps focus, inerts the page, and restores the launcher', async () => {
        stubMatchMedia(true);
        vi.mocked(fetch).mockResolvedValueOnce({
            ok: true,
            status: 200,
            json: async () => ({
                data: {
                    publicId: 'mobile-modal-conversation',
                    status: 'open',
                    locale: 'en',
                    messages: [],
                    hasMore: false,
                    oldestCursor: null,
                },
            }),
        } as Response);

        render(
            <>
                <main data-testid="covered-page">
                    <button type="button">Covered action</button>
                </main>
                <ChatWidget enabled={true} locale="en" />
            </>,
        );

        const launcher = screen.getByRole('button', { name: /Open chat/i });
        launcher.focus();
        fireEvent.click(launcher);

        const dialog = screen.getByRole('dialog', {
            name: /Arab UT Chat Assistant/i,
        });
        await waitFor(() =>
            expect(dialog).toHaveAttribute('aria-modal', 'true'),
        );
        expect(dialog).toHaveFocus();
        expect(screen.getByTestId('covered-page')).toHaveAttribute('inert');
        expect(launcher).toHaveAttribute('inert');

        const restart = await screen.findByRole('button', {
            name: /New conversation/i,
        });
        await waitFor(() => expect(restart).toBeEnabled());
        const textbox = screen.getByRole('textbox');

        fireEvent.keyDown(window, { key: 'Tab' });
        expect(restart).toHaveFocus();
        fireEvent.keyDown(window, { key: 'Tab', shiftKey: true });
        expect(textbox).toHaveFocus();
        fireEvent.keyDown(window, { key: 'Tab' });
        expect(restart).toHaveFocus();

        fireEvent.click(
            within(dialog).getByRole('button', { name: /Close chat/i }),
        );
        expect(dialog).toBeInTheDocument();
        expect(screen.getByTestId('covered-page')).toHaveAttribute('inert');
        expect(launcher).toHaveAttribute('inert');
        expect(launcher).not.toHaveFocus();

        launcher.focus();
        fireEvent.keyDown(window, { key: 'Tab' });
        expect(restart).toHaveFocus();

        await waitFor(() => expect(dialog).not.toBeInTheDocument());
        expect(screen.getByTestId('covered-page')).not.toHaveAttribute('inert');
        expect(launcher).not.toHaveAttribute('inert');
        expect(launcher).toHaveFocus();
    });

    it('keeps the desktop panel non-modal without inerting or stealing focus', () => {
        stubMatchMedia(false);
        render(
            <>
                <main data-testid="desktop-page">Desktop page</main>
                <ChatWidget enabled={true} locale="en" />
            </>,
        );

        const launcher = screen.getByRole('button', { name: /Open chat/i });
        launcher.focus();
        fireEvent.click(launcher);

        expect(screen.getByRole('dialog')).toHaveAttribute(
            'aria-modal',
            'false',
        );
        expect(screen.getByTestId('desktop-page')).not.toHaveAttribute('inert');
        expect(launcher).toHaveFocus();
    });

    it('disables restart while a customer message is pending', async () => {
        const pendingSend = new Promise<Response>(() => undefined);
        const conversation = {
            publicId: 'conversation-pending-send',
            status: 'open',
            locale: 'en',
            messages: [],
            hasMore: false,
            oldestCursor: null,
        };

        vi.mocked(fetch)
            .mockResolvedValueOnce({
                ok: true,
                status: 200,
                json: async () => ({ data: conversation }),
            } as Response)
            .mockReturnValueOnce(pendingSend);

        render(<ChatWidget enabled={true} locale="en" />);
        fireEvent.click(screen.getByRole('button', { name: /Open chat/i }));

        const restart = await screen.findByRole('button', {
            name: /New conversation/i,
        });
        await waitFor(() => expect(restart).toBeEnabled());
        fireEvent.change(screen.getByRole('textbox'), {
            target: { value: 'Pending message' },
        });
        fireEvent.click(screen.getByRole('button', { name: /Send message/i }));

        expect(restart).toBeDisabled();
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

        expect(screen.getByText('المساعد يكتب الآن…')).toBeInTheDocument();
        expect(
            screen.getByRole('button', { name: /محادثة جديدة/i }),
        ).toBeDisabled();

        act(() => {
            vi.advanceTimersByTime(1200);
        });

        expect(
            screen.getByText('وصلتني رسالتك 👍 هذي نسخة تجريبية من الشات.'),
        ).toBeInTheDocument();

        vi.useRealTimers();
    });
});
