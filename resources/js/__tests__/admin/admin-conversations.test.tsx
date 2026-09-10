import {
    cleanup,
    fireEvent,
    render,
    screen,
    within,
} from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { englishAdminUi } from '@/__tests__/admin/admin-test-fixtures';
import AdminConversationsIndexPage from '@/pages/admin/conversations/index';
import AdminConversationDetailPage from '@/pages/admin/conversations/show';
import type {
    AdminConversationDetail,
    AdminConversationDetailPageProps,
    AdminConversationRow,
    AdminConversationsPageProps,
    AdminIdentity,
    AdminNavigationItem,
    AdminSupportTicket,
} from '@/types/admin';

const inertia = vi.hoisted(() => ({
    get: vi.fn(),
    patch: vi.fn(),
    post: vi.fn(),
    reload: vi.fn(),
}));

const pageState = vi.hoisted(() => ({
    component: 'admin/conversations/index',
    url: '/admin/conversations',
    props: {} as AdminConversationsPageProps | AdminConversationDetailPageProps,
}));

vi.mock('@inertiajs/react', () => ({
    Head: ({ title }: { title: string }) => <title>{title}</title>,
    Link: ({ children, href, ...props }: React.ComponentProps<'a'>) => (
        <a href={typeof href === 'string' ? href : ''} {...props}>
            {children}
        </a>
    ),
    router: inertia,
    usePage: () => ({
        component: pageState.component,
        props: pageState.props,
        url: pageState.url,
    }),
}));

const navigation: AdminNavigationItem[] = [
    { key: 'overview', label: 'Overview', url: '/admin' },
    {
        key: 'conversations',
        label: 'Conversations',
        url: '/admin/conversations',
    },
];

const adminIdentity: AdminIdentity = {
    name: 'Operations Owner',
    role: 'admin',
};

beforeEach(() => {
    Element.prototype.scrollIntoView = vi.fn();
});

function indexRow(): AdminConversationRow {
    return {
        shortId: 'CHT-AB12CD',
        url: '/admin/conversations/CHT-AB12CD',
        ticketNumber: 'TKT-AB12CD',
        ticketStatus: 'open',
        hasUnread: true,
        status: 'open',
        locale: 'ar',
        ownerType: 'customer',
        customerName: 'Fahad Al-Otaibi',
        messageCount: 3,
        lastMessageAt: '2026-08-24T10:00:00+00:00',
        createdAt: '2026-08-24T09:00:00+00:00',
    };
}

function indexProps(): AdminConversationsPageProps {
    return {
        locale: 'en',
        direction: 'ltr',
        adminUi: englishAdminUi,
        adminIdentity,
        adminNavigation: navigation,
        permissions: ['chat.view', 'chat.reply'],
        rows: [indexRow()],
        pagination: {
            currentPage: 1,
            lastPage: 1,
            perPage: 25,
            total: 1,
            from: 1,
            to: 1,
        },
        filters: { per_page: 25, page: 1 },
        filterOptions: {
            statuses: [{ value: 'open', label: 'Open' }],
            locales: [{ value: 'ar', label: 'Arabic' }],
            ticketStatuses: [{ value: 'open', label: 'Open' }],
            perPageOptions: [15, 25, 50, 100],
        },
        logoutUrl: '/logout',
    };
}

function detailProps(): AdminConversationDetailPageProps {
    const conversation: AdminConversationDetail = {
        shortId: 'CHT-AB12CD',
        url: '/admin/conversations/CHT-AB12CD',
        handoffState: 'active',
        status: 'open',
        locale: 'ar',
        ownerType: 'customer',
        customerName: 'Fahad Al-Otaibi',
        messageCount: 3,
        lastMessageAt: '2026-08-24T10:00:00+00:00',
        createdAt: '2026-08-24T09:00:00+00:00',
        closedAt: null,
        closeReason: null,
    };

    const ticket: AdminSupportTicket = {
        number: 'TKT-AB12CD',
        resolveUrl: '/admin/tickets/TKT-AB12CD',
        status: 'open',
        subject: 'Order delay',
        assignedAdminName: 'Someone Else',
        assignedToMe: false,
        openedAt: '2026-08-24T09:30:00+00:00',
    };

    return {
        locale: 'en',
        direction: 'ltr',
        adminUi: englishAdminUi,
        adminIdentity,
        adminNavigation: navigation,
        permissions: ['chat.view', 'chat.reply'],
        conversation,
        ticket,
        canReply: true,
        messages: [
            {
                publicId: '01JMSG00000000000000000001',
                senderType: 'customer',
                messageType: 'text',
                content: 'Where is my order?',
                staffName: null,
                createdAt: '2026-08-24T09:30:00+00:00',
            },
        ],
        turns: [
            {
                ordinal: 1,
                status: 'completed',
                promptVersion: 'v1.0.0',
                createdAt: '2026-08-24T09:31:00+00:00',
                latestRunStatus: 'completed',
                latencyMs: 450,
                inputTokens: 120,
                outputTokens: 45,
                model: 'gemini-2.5-flash',
            },
        ],
        replyUrl: '/admin/conversations/CHT-AB12CD/reply',
        noteUrl: '/admin/conversations/CHT-AB12CD/note',
        takeOverUrl: '/admin/conversations/CHT-AB12CD/take-over',
        logoutUrl: '/logout',
    };
}

describe('AdminConversationsIndexPage', () => {
    beforeEach(() => {
        pageState.component = 'admin/conversations/index';
        pageState.url = '/admin/conversations';
        pageState.props = indexProps();
    });

    afterEach(() => {
        cleanup();
        vi.clearAllMocks();
    });

    it('links each row to the server-built conversation url', () => {
        render(<AdminConversationsIndexPage />);

        const links = screen.getAllByRole('link', {
            name: /CHT-AB12CD/,
        });

        expect(links.length).toBeGreaterThan(0);

        for (const link of links) {
            expect(link).toHaveAttribute(
                'href',
                '/admin/conversations/CHT-AB12CD',
            );
        }
    });
});

describe('AdminConversationDetailPage', () => {
    beforeEach(() => {
        pageState.component = 'admin/conversations/show';
        pageState.url = '/admin/conversations/CHT-AB12CD';
        pageState.props = detailProps();
    });

    afterEach(() => {
        cleanup();
        vi.clearAllMocks();
    });

    it('shows the turn ordinal instead of an internal ULID', () => {
        render(<AdminConversationDetailPage />);

        expect(
            screen.getByText(englishAdminUi.conversationDetail.turnId),
        ).toBeVisible();
        expect(within(screen.getByRole('table')).getByText('1')).toBeVisible();
    });

    it('posts a reply to the server-built reply url', () => {
        render(<AdminConversationDetailPage />);

        fireEvent.change(
            screen.getByLabelText(
                englishAdminUi.conversationDetail.replyToCustomer,
            ),
            { target: { value: 'Checking on it now.' } },
        );

        fireEvent.click(
            screen.getByRole('button', {
                name: englishAdminUi.conversationDetail.sendReply,
            }),
        );

        expect(inertia.post).toHaveBeenCalledWith(
            '/admin/conversations/CHT-AB12CD/reply',
            { content: 'Checking on it now.' },
            expect.any(Object),
        );
    });

    it('takes over via the server-built take-over url', () => {
        render(<AdminConversationDetailPage />);

        fireEvent.click(
            screen.getByRole('button', {
                name: englishAdminUi.conversationDetail.takeOver,
            }),
        );

        expect(inertia.post).toHaveBeenCalledWith(
            '/admin/conversations/CHT-AB12CD/take-over',
            {},
            expect.any(Object),
        );
    });

    it('resolves the ticket via the server-built ticket url', () => {
        render(<AdminConversationDetailPage />);

        fireEvent.click(
            screen.getByRole('button', {
                name: englishAdminUi.conversationDetail.resolveTicket,
            }),
        );

        expect(inertia.patch).toHaveBeenCalledWith(
            '/admin/tickets/TKT-AB12CD',
            { status: 'resolved' },
            expect.any(Object),
        );
    });

    it('posts a note to the server-built note url', () => {
        render(<AdminConversationDetailPage />);

        fireEvent.click(
            screen.getByRole('button', {
                name: englishAdminUi.conversationDetail.internalNoteLabel,
            }),
        );

        fireEvent.change(
            screen.getByLabelText(
                englishAdminUi.conversationDetail.internalNoteLabel,
            ),
            { target: { value: 'Only the team sees this.' } },
        );

        fireEvent.click(
            screen.getByRole('button', {
                name: englishAdminUi.conversationDetail.saveNote,
            }),
        );

        expect(inertia.post).toHaveBeenCalledWith(
            '/admin/conversations/CHT-AB12CD/note',
            { content: 'Only the team sees this.' },
            expect.any(Object),
        );
    });

    it('renders no internal 26-character ULID in text or in an attribute', () => {
        render(<AdminConversationDetailPage />);

        const ulid = /[0-9A-HJKMNP-TV-Z]{26}/i;

        expect(document.body.textContent ?? '').not.toMatch(ulid);

        for (const element of document.body.querySelectorAll(
            '[title], [aria-label], [placeholder]',
        )) {
            for (const attribute of ['title', 'aria-label', 'placeholder']) {
                const value = element.getAttribute(attribute);

                if (value !== null) {
                    expect(value).not.toMatch(ulid);
                }
            }
        }
    });
});
