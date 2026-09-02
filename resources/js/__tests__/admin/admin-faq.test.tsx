import {
    cleanup,
    fireEvent,
    render,
    screen,
    within,
} from '@testing-library/react';
import React from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import {
    englishAdminUi,
    sampleAdminCategoriesPageProps,
} from '@/__tests__/admin/admin-test-fixtures';
import AdminFaqPage from '@/pages/admin/marketing/faq';
import type { AdminFaqPageProps, AdminFaqRow } from '@/types/admin';

const inertia = vi.hoisted(() => ({
    get: vi.fn(),
    reload: vi.fn(),
    post: vi.fn(),
}));

const pageState = vi.hoisted(() => ({
    component: 'admin/marketing/faq',
    url: '/admin/marketing/faq',
    props: {} as AdminFaqPageProps,
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
    useHttp: () => ({
        data: {},
        errors: {},
        processing: false,
        setData: vi.fn(),
        submit: vi.fn(),
    }),
}));

const sampleRows: AdminFaqRow[] = [
    {
        answerAr: 'إجابة السؤال الأول.',
        answerEn: 'Answer to question one.',
        id: 'faq-1',
        isVisible: true,
        questionAr: 'ما هو السؤال الأول؟',
        questionEn: 'What is question one?',
        sortOrder: 10,
        updatedAt: '2026-09-02T10:00:00Z',
    },
    {
        answerAr: 'إجابة السؤال الثاني.',
        answerEn: 'Answer to question two.',
        id: 'faq-2',
        isVisible: true,
        questionAr: 'ما هو السؤال الثاني؟',
        questionEn: 'What is question two?',
        sortOrder: 20,
        updatedAt: '2026-09-02T11:00:00Z',
    },
    {
        answerAr: 'إجابة السؤال الثالث.',
        answerEn: 'Answer to question three.',
        id: 'faq-3',
        isVisible: false,
        questionAr: 'ما هو السؤال الثالث؟',
        questionEn: 'What is question three?',
        sortOrder: 30,
        updatedAt: '2026-09-02T12:00:00Z',
    },
];

function defaultProps(): AdminFaqPageProps {
    return {
        adminIdentity: sampleAdminCategoriesPageProps.adminIdentity,
        adminNavigation: sampleAdminCategoriesPageProps.adminNavigation,
        adminUi: englishAdminUi,
        canManage: true,
        createUrl: '/admin/api/marketing/faq',
        deleteUrlTemplate: '/admin/api/marketing/faq/__ID__',
        direction: 'ltr',
        entries: sampleRows,
        locale: 'en',
        logoutUrl: '/logout',
        moveUrlTemplate: '/admin/api/marketing/faq/__ID__/move',
        permissions: ['marketing.view', 'marketing.manage'],
        updateUrlTemplate: '/admin/api/marketing/faq/__ID__',
        visibilityUrlTemplate: '/admin/api/marketing/faq/__ID__/visibility',
    };
}

describe('AdminFaqPage', () => {
    beforeEach(() => {
        pageState.props = defaultProps();
    });

    afterEach(() => {
        cleanup();
        vi.clearAllMocks();
    });

    it('renders the header title and all rows with status badges', () => {
        render(<AdminFaqPage {...pageState.props} />);

        const copy = englishAdminUi.faq!;

        expect(
            screen.getByRole('heading', {
                level: 1,
                name: copy.title,
            }),
        ).toBeInTheDocument();

        const table = screen.getByRole('table', {
            name: copy.tableLabel,
        });

        expect(within(table).getByText('ما هو السؤال الأول؟')).toBeVisible();
        expect(within(table).getByText('What is question one?')).toBeVisible();
        expect(within(table).getByText('ما هو السؤال الثاني؟')).toBeVisible();
        expect(within(table).getByText('ما هو السؤال الثالث؟')).toBeVisible();

        const visibleBadges = within(table).getAllByText(copy.stateVisible);
        expect(visibleBadges).toHaveLength(2);

        const hiddenBadges = within(table).getAllByText(copy.stateHidden);
        expect(hiddenBadges).toHaveLength(1);
    });

    it('disables move up on the first entry and move down on the last entry', () => {
        render(<AdminFaqPage {...pageState.props} />);

        const copy = englishAdminUi.faq!;
        const table = screen.getByRole('table', {
            name: copy.tableLabel,
        });

        // First entry: move up disabled, move down enabled
        const moveUpFirst = within(table).getByRole('button', {
            name: `${copy.moveUp} (What is question one?)`,
        });
        const moveDownFirst = within(table).getByRole('button', {
            name: `${copy.moveDown} (What is question one?)`,
        });
        expect(moveUpFirst).toBeDisabled();
        expect(moveDownFirst).toBeEnabled();

        // Middle entry: both enabled
        const moveUpMiddle = within(table).getByRole('button', {
            name: `${copy.moveUp} (What is question two?)`,
        });
        const moveDownMiddle = within(table).getByRole('button', {
            name: `${copy.moveDown} (What is question two?)`,
        });
        expect(moveUpMiddle).toBeEnabled();
        expect(moveDownMiddle).toBeEnabled();

        // Last entry: move up enabled, move down disabled
        const moveUpLast = within(table).getByRole('button', {
            name: `${copy.moveUp} (What is question three?)`,
        });
        const moveDownLast = within(table).getByRole('button', {
            name: `${copy.moveDown} (What is question three?)`,
        });
        expect(moveUpLast).toBeEnabled();
        expect(moveDownLast).toBeDisabled();
    });

    it('opens the edit dialog populated with the entry values', () => {
        render(<AdminFaqPage {...pageState.props} />);

        const copy = englishAdminUi.faq!;
        const table = screen.getByRole('table', {
            name: copy.tableLabel,
        });

        const editButton = within(table).getByRole('button', {
            name: `${copy.edit} (What is question two?)`,
        });
        fireEvent.click(editButton);

        expect(
            screen.getByRole('heading', {
                level: 2,
                name: copy.editDialogTitle,
            }),
        ).toBeInTheDocument();

        expect(
            screen.getByDisplayValue('ما هو السؤال الثاني؟'),
        ).toBeInTheDocument();
        expect(
            screen.getByDisplayValue('What is question two?'),
        ).toBeInTheDocument();
        expect(
            screen.getByDisplayValue('إجابة السؤال الثاني.'),
        ).toBeInTheDocument();
        expect(
            screen.getByDisplayValue('Answer to question two.'),
        ).toBeInTheDocument();
    });

    it('hides all write controls when canManage is false', () => {
        pageState.props = {
            ...defaultProps(),
            canManage: false,
        };

        render(<AdminFaqPage {...pageState.props} />);

        const copy = englishAdminUi.faq!;

        // "New question" button is hidden
        expect(
            screen.queryByRole('button', {
                name: copy.newQuestion,
            }),
        ).toBeNull();

        // No action buttons (move, edit, visibility, delete) rendered
        expect(
            screen.queryByRole('button', {
                name: new RegExp(
                    `${copy.moveUp}|${copy.moveDown}|${copy.edit}|${copy.delete}`,
                ),
            }),
        ).toBeNull();
    });
});
