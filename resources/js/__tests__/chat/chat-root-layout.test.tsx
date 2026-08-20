import { cleanup, render, screen } from '@testing-library/react';
import type React from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import ChatRootLayout from '@/layouts/chat-root-layout';

const pageState = vi.hoisted(() => ({
    component: 'store/home',
    props: {
        locale: 'en',
        chat: { enabled: true, demoAssistant: false },
    } as Record<string, unknown>,
    inContext: true,
}));

vi.mock('@inertiajs/react', () => ({
    usePage: () => {
        if (!pageState.inContext) {
            throw new Error(
                'usePage must be used within the Inertia PageContext provider.',
            );
        }

        return { component: pageState.component, props: pageState.props };
    },
}));

describe('ChatRootLayout & Inertia Context', () => {
    beforeEach(() => {
        pageState.component = 'store/home';
        pageState.inContext = true;
        pageState.props = {
            locale: 'en',
            chat: { enabled: true, demoAssistant: false },
        };
        vi.stubGlobal('fetch', vi.fn());
        Element.prototype.scrollIntoView = vi.fn();
    });

    afterEach(() => {
        cleanup();
        vi.restoreAllMocks();
    });

    it('renders page children and mounts ChatWidget within PageContext', () => {
        render(
            <ChatRootLayout>
                <div data-testid="page-content">Storefront Page Content</div>
            </ChatRootLayout>,
        );

        expect(screen.getByTestId('page-content')).toBeInTheDocument();
        expect(
            screen.getByRole('button', { name: /Open chat/i }),
        ).toBeInTheDocument();
    });

    it('automatically propagates English locale and labels from shared Inertia props', () => {
        pageState.props = {
            locale: 'en',
            chat: { enabled: true, demoAssistant: false },
        };

        render(
            <ChatRootLayout>
                <div>English Page</div>
            </ChatRootLayout>,
        );

        const launcher = screen.getByRole('button', { name: /Open chat/i });
        expect(launcher).toBeInTheDocument();
    });

    it('automatically propagates Arabic locale and labels when locale is ar', () => {
        pageState.props = {
            locale: 'ar',
            chat: { enabled: true, demoAssistant: false },
        };

        render(
            <ChatRootLayout>
                <div>Arabic Page</div>
            </ChatRootLayout>,
        );

        const launcher = screen.getByRole('button', { name: /فتح الشات/i });
        expect(launcher).toBeInTheDocument();
    });

    it('passes the account surface when the Inertia component is an account page', () => {
        pageState.component = 'account/overview';

        const { container } = render(
            <ChatRootLayout>
                <div>Account Page</div>
            </ChatRootLayout>,
        );

        expect(container.querySelector('.chat-widget-root')).toHaveClass(
            'chat-widget-root--account',
        );
    });

    it('fails if rendered outside Inertia PageContext', () => {
        pageState.inContext = false;

        expect(() => {
            render(
                <ChatRootLayout>
                    <div>Outside Context</div>
                </ChatRootLayout>,
            );
        }).toThrow(
            /usePage must be used within the Inertia PageContext provider/i,
        );
    });
});
