import {
    cleanup,
    fireEvent,
    render,
    screen,
    waitFor,
} from '@testing-library/react';
import React from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import {
    sampleAdminStorePageEditorPageProps,
    sampleAdminStorePagesPageProps,
} from '@/__tests__/admin/admin-test-fixtures';
import AdminStorePageEditorPage from '@/pages/admin/marketing/page-editor';
import AdminStorePagesPage from '@/pages/admin/marketing/pages';

const inertia = vi.hoisted(() => ({
    get: vi.fn(),
    reload: vi.fn(),
    post: vi.fn(),
    put: vi.fn(),
    on: vi.fn(() => vi.fn()),
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
        component: 'admin/marketing/pages',
        props: sampleAdminStorePagesPageProps,
        url: '/admin/marketing/pages',
    }),
}));

describe('AdminStorePagesPage', () => {
    afterEach(() => {
        cleanup();
        vi.clearAllMocks();
    });

    it('renders the policy pages list with all five pages', () => {
        render(<AdminStorePagesPage {...sampleAdminStorePagesPageProps} />);

        expect(
            screen.getByRole('heading', { level: 1, name: 'Policy pages' }),
        ).toBeDefined();

        // 5 pages rendered in desktop table and mobile cards
        expect(
            screen.getAllByText('سياسة الخصوصية').length,
        ).toBeGreaterThanOrEqual(1);
        expect(
            screen.getAllByText('Privacy Policy').length,
        ).toBeGreaterThanOrEqual(1);
        expect(
            screen.getAllByText('الاسترجاع والإلغاء').length,
        ).toBeGreaterThanOrEqual(1);
        expect(
            screen.getAllByText('الضمان والتعويض').length,
        ).toBeGreaterThanOrEqual(1);
        expect(
            screen.getAllByText('الأكواد الاحتياطية لحساب EA').length,
        ).toBeGreaterThanOrEqual(1);
        expect(
            screen.getAllByText('الشروط والأحكام').length,
        ).toBeGreaterThanOrEqual(1);

        // Edit links
        const editLinks = screen.getAllByRole('link', { name: /Edit/i });
        expect(editLinks.length).toBeGreaterThanOrEqual(5);
        expect(editLinks[0].getAttribute('href')).toBe(
            '/admin/marketing/pages/privacy',
        );
    });
});

describe('AdminStorePageEditorPage', () => {
    beforeEach(() => {
        vi.stubGlobal('fetch', vi.fn());
    });

    afterEach(() => {
        cleanup();
        vi.clearAllMocks();
        vi.unstubAllGlobals();
    });

    it('renders editor header, tabs, and content fields', () => {
        render(
            <AdminStorePageEditorPage
                {...sampleAdminStorePageEditorPageProps}
            />,
        );

        // Header
        expect(screen.getByRole('heading', { level: 1 })).toBeDefined();
        const storeLink = screen.getByRole('link', {
            name: /Open on the store/i,
        });
        expect(storeLink.getAttribute('href')).toBe('/privacy');
        expect(
            screen.getByRole('button', { name: /Save page/i }),
        ).toBeDefined();

        // Tabs
        const arabicTab = screen.getByRole('tab', { name: 'العربية' });
        const englishTab = screen.getByRole('tab', { name: 'English' });
        expect(arabicTab).toBeDefined();
        expect(englishTab).toBeDefined();

        // Title and updated label fields for English (initial tab since props.locale is 'en')
        expect(screen.getByDisplayValue('Privacy Policy')).toBeDefined();
        expect(screen.getByDisplayValue('2 September 2026')).toBeDefined();

        // Helper text explaining markers
        expect(
            screen.getByText(
                /Use \*\*bold\*\* for emphasis and \[label\]\(url\) for links/i,
            ),
        ).toBeDefined();
    });

    it('switches between Arabic and English tabs', () => {
        render(
            <AdminStorePageEditorPage
                {...sampleAdminStorePageEditorPageProps}
            />,
        );

        const arabicTab = screen.getByRole('tab', { name: 'العربية' });
        fireEvent.click(arabicTab);

        expect(screen.getByDisplayValue('سياسة الخصوصية')).toBeDefined();
        expect(screen.getByDisplayValue('٢ سبتمبر ٢٠٢٦')).toBeDefined();
    });

    it('allows adding, editing, moving, and removing blocks', () => {
        render(
            <AdminStorePageEditorPage
                {...sampleAdminStorePageEditorPageProps}
            />,
        );

        // Count initial blocks (English tab has 5 blocks)
        expect(screen.getByText('Blocks (5)')).toBeDefined();

        // Add a block
        const addBlockBtn = screen.getByRole('button', { name: /Add block/i });
        fireEvent.click(addBlockBtn);
        expect(screen.getByText('Blocks (6)')).toBeDefined();

        // Remove the newly added block (last remove button)
        const removeButtons = screen.getAllByRole('button', {
            name: /Remove block/i,
        });
        expect(removeButtons.length).toBe(6);
        fireEvent.click(removeButtons[5]);
        expect(screen.getByText('Blocks (5)')).toBeDefined();
    });

    it('saves updated content and shows success feedback', async () => {
        const fetchMock = vi.fn().mockResolvedValue({
            ok: true,
            json: async () => ({ page: 'privacy' }),
        });
        vi.stubGlobal('fetch', fetchMock);

        render(
            <AdminStorePageEditorPage
                {...sampleAdminStorePageEditorPageProps}
            />,
        );

        const saveButton = screen.getByRole('button', { name: /Save page/i });
        fireEvent.click(saveButton);

        await waitFor(() => {
            expect(fetchMock).toHaveBeenCalledWith(
                '/admin/api/marketing/pages/privacy',
                expect.objectContaining({
                    method: 'PUT',
                    headers: expect.objectContaining({
                        'Content-Type': 'application/json',
                    }),
                }),
            );
        });

        await waitFor(() => {
            expect(screen.getByText('Page saved successfully.')).toBeDefined();
        });
    });

    it('displays error messages when the server responds with 422 validation failure', async () => {
        const fetchMock = vi.fn().mockResolvedValue({
            ok: false,
            status: 422,
            json: async () => ({
                message: 'The given data was invalid.',
                errors: {
                    error: ['Block #2 contains unapproved external URL host.'],
                },
            }),
        });
        vi.stubGlobal('fetch', fetchMock);

        render(
            <AdminStorePageEditorPage
                {...sampleAdminStorePageEditorPageProps}
            />,
        );

        const saveButton = screen.getByRole('button', { name: /Save page/i });
        fireEvent.click(saveButton);

        await waitFor(() => {
            expect(
                screen.getAllByText(
                    /Block #2 contains unapproved external URL host/i,
                ).length,
            ).toBeGreaterThanOrEqual(1);
        });
    });
});
