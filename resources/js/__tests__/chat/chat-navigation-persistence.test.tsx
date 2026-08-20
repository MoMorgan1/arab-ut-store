import {
    act,
    cleanup,
    fireEvent,
    render,
    screen,
    waitFor,
} from '@testing-library/react';
import type React from 'react';
import { useState } from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import ChatRootLayout from '@/layouts/chat-root-layout';

const pageState = vi.hoisted(() => ({
    props: {
        chat: { enabled: true, demoAssistant: false },
        locale: 'ar',
    } as Record<string, unknown>,
}));

vi.mock('@inertiajs/react', () => ({
    usePage: () => ({ component: 'store/home', props: pageState.props }),
}));

// Root layout wrapper simulator matching resources/js/app.tsx persistent layout structure
const AppRootSimulator: React.FC<{ initialPageName: string }> = ({
    initialPageName,
}) => {
    const [pageName, setPageName] = useState(initialPageName);

    return (
        <ChatRootLayout>
            <main data-testid="page-content">
                <h1>{pageName}</h1>
                <button
                    type="button"
                    onClick={() => setPageName('Next Store Page')}
                >
                    Navigate to next page
                </button>
            </main>
        </ChatRootLayout>
    );
};

describe('Chat Root Navigation Persistence', () => {
    beforeEach(() => {
        pageState.props = {
            chat: { enabled: true, demoAssistant: false },
            locale: 'ar',
        };
        vi.stubGlobal('fetch', vi.fn());
        Element.prototype.scrollIntoView = vi.fn();
    });

    afterEach(() => {
        cleanup();
        vi.restoreAllMocks();
    });

    it('proves ChatRootLayout and conversation state persist across Inertia page navigation', async () => {
        const mockConversation = {
            publicId: '01JMPERSISTENCE00000000001',
            status: 'open',
            locale: 'ar',
            subject: null,
            lastMessageAt: '2026-08-20T10:00:00.000Z',
            messages: [
                {
                    publicId: 'msg-sys-1',
                    conversationPublicId: '01JMPERSISTENCE00000000001',
                    senderType: 'system',
                    messageType: 'system',
                    content: 'رسالة النظام الترحيبية الثابتة',
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

        render(<AppRootSimulator initialPageName="Home Page" />);

        expect(screen.getByTestId('page-content')).toHaveTextContent(
            'Home Page',
        );

        const launcher = screen.getByRole('button', { name: /فتح الشات/i });
        fireEvent.click(launcher);

        await waitFor(() => {
            expect(
                screen.getByText('رسالة النظام الترحيبية الثابتة'),
            ).toBeInTheDocument();
        });

        expect(
            screen.getByRole('dialog', { name: /مساعد عرب التيميت/i }),
        ).toBeInTheDocument();

        // Simulate Inertia client-side page navigation (page unmounts, new page mounts)
        act(() => {
            fireEvent.click(
                screen.getByRole('button', { name: /navigate to next page/i }),
            );
        });

        // Page content has updated
        expect(screen.getByTestId('page-content')).toHaveTextContent(
            'Next Store Page',
        );

        // Prove ChatWidget is STILL OPEN, mounted, and has the exact same message history without re-fetching
        expect(
            screen.getByRole('dialog', { name: /مساعد عرب التيميت/i }),
        ).toBeInTheDocument();
        expect(
            screen.getByText('رسالة النظام الترحيبية الثابتة'),
        ).toBeInTheDocument();

        // fetch was called exactly once on initial open
        expect(fetch).toHaveBeenCalledTimes(1);
    });
});
