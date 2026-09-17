import { cleanup, render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import React from 'react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { englishAdminUi } from '@/__tests__/admin/admin-test-fixtures';
import AdminFulfillmentPage from '@/pages/admin/fulfillment/index';
import type {
    AdminFulfillmentPageProps,
    AdminFulfillmentRow,
} from '@/types/admin';

const inertia = vi.hoisted(() => ({
    get: vi.fn(),
    post: vi.fn(),
    reload: vi.fn(),
}));

const http = vi.hoisted(() => ({
    setData: vi.fn(),
    submit: vi.fn(),
}));

const pageState = vi.hoisted(() => ({
    component: 'admin/fulfillment/index',
    props: {} as AdminFulfillmentPageProps,
    url: '/admin/fulfillment',
}));

vi.mock('@inertiajs/react', () => ({
    Head: ({ title }: { title: string }) => <title>{title}</title>,
    Link: ({ children, href, ...props }: React.ComponentProps<'a'>) => (
        <a href={typeof href === 'string' ? href : ''} {...props}>
            {children}
        </a>
    ),
    router: inertia,
    useHttp: () => ({
        data: {},
        errors: {},
        processing: false,
        setData: http.setData,
        submit: http.submit,
    }),
    usePage: () => ({
        component: pageState.component,
        props: pageState.props,
        url: pageState.url,
    }),
}));

/** An item at a supplier, reading healthily. */
const healthy: AdminFulfillmentRow = {
    actions: [],
    alarms: [],
    blocker: null,
    cost: { amountMinor: '11840', currency: 'SAR' },
    id: 'item-healthy',
    itemStatus: 'in_progress',
    job: {
        band: 'background',
        holdReason: null,
        observedAt: '2026-09-17T13:59:36Z',
        observedState: null,
        phase: 'coins',
        pollFailures: 0,
        presentation: 'transferring',
        status: 'in_progress',
    },
    orderNumber: 'AUT-1041',
    paidAt: '2026-09-17T12:44:00Z',
    placement: {
        challengeCount: 0,
        phase: 'coins',
        placedAt: '2026-09-17T12:48:00Z',
        reference: '574402',
        supplier: 'fft',
    },
    platform: 'console',
    progress: { done: 240_000, total: 600_000, unit: 'coins' },
    service: 'coins',
};

/** The row the screen exists for: paid, automated, and never placed. */
const unplaced: AdminFulfillmentRow = {
    actions: ['send'],
    alarms: [
        {
            blocked: true,
            circuitOpen: false,
            kind: 'unplaced',
            notifiedAt: '2026-09-17T14:20:00Z',
            phase: null,
            pollFailures: null,
            quietMinutes: null,
            raisedAt: '2026-09-17T14:20:00Z',
            reason: 'budget_unavailable',
        },
    ],
    blocker: { blocks: true, reason: 'budget_unavailable' },
    cost: null,
    id: 'item-unplaced',
    itemStatus: 'received',
    job: null,
    orderNumber: 'AUT-1042',
    paidAt: '2026-09-17T14:02:00Z',
    placement: null,
    platform: 'console',
    progress: null,
    service: 'sbc',
};

/** A challenge item holding three solves, one of which failed. */
const multiSolve: AdminFulfillmentRow = {
    actions: ['retry_challenge'],
    alarms: [],
    blocker: null,
    cost: null,
    id: 'item-solves',
    itemStatus: 'in_progress',
    job: {
        band: 'background',
        holdReason: null,
        observedAt: '2026-09-17T14:20:00Z',
        observedState: 'solving',
        phase: 'challenge',
        pollFailures: 0,
        presentation: 'in_progress',
        status: 'in_progress',
    },
    orderNumber: 'AUT-1051',
    paidAt: '2026-09-17T10:00:00Z',
    placement: {
        challengeCount: 3,
        phase: 'challenge',
        placedAt: '2026-09-17T10:05:00Z',
        reference: '574401',
        supplier: 'fft',
    },
    platform: 'console',
    progress: { done: 1, total: 3, unit: 'solves' },
    service: 'sbc',
};

/** A placed item whose supplier sits inside its circuit cooldown. */
const stalled: AdminFulfillmentRow = {
    actions: ['resume'],
    alarms: [
        {
            blocked: false,
            circuitOpen: true,
            kind: 'stalled',
            notifiedAt: null,
            phase: 'coins',
            pollFailures: null,
            quietMinutes: 41,
            raisedAt: '2026-09-17T13:30:00Z',
            reason: null,
        },
    ],
    blocker: null,
    cost: { amountMinor: '9625', currency: 'SAR' },
    id: 'item-stalled',
    itemStatus: 'in_progress',
    job: {
        band: 'background',
        holdReason: null,
        observedAt: '2026-09-17T13:20:00Z',
        observedState: 'interrupted',
        phase: 'coins',
        pollFailures: 0,
        presentation: 'stopped',
        status: 'in_progress',
    },
    orderNumber: 'AUT-1033',
    paidAt: '2026-09-17T09:34:00Z',
    placement: {
        challengeCount: 0,
        phase: 'coins',
        placedAt: '2026-09-17T09:40:00Z',
        reference: '574339',
        supplier: 'fft',
    },
    platform: 'console',
    progress: { done: 310_000, total: 600_000, unit: 'coins' },
    service: 'coins',
};

function baseProps(
    overrides: Partial<AdminFulfillmentPageProps> = {},
): AdminFulfillmentPageProps {
    return {
        adminIdentity: { name: 'Mohamed', role: 'admin' },
        adminNavigation: [],
        adminUi: englishAdminUi,
        canAct: true,
        canSeeCost: true,
        direction: 'ltr',
        // The clock the page measures ages from, matching the frozen system
        // time below so the assertions stay deterministic.
        generatedAt: '2026-09-17T14:40:00+00:00',
        filterOptions: {
            alarms: [
                { label: 'All alarms', value: 'all' },
                { label: 'Any alarm', value: 'any' },
                { label: 'Unplaced', value: 'unplaced' },
                { label: 'Silent', value: 'silent' },
                { label: 'Stalled', value: 'stalled' },
                { label: 'No alarm', value: 'none' },
            ],
            holds: [{ label: 'All holds', value: 'all' }],
            perPageOptions: [15, 25, 50, 100],
            phases: [{ label: 'All phases', value: 'all' }],
            reasonCodes: [
                { label: 'Never placed', value: 'never_placed' },
                { label: 'Callback never arrived', value: 'callback_lost' },
                {
                    label: 'Supplier stopped reporting',
                    value: 'supplier_stalled',
                },
            ],
            services: [{ label: 'All services', value: 'all' }],
            statuses: [{ label: 'All states', value: 'all' }],
            suppliers: [{ label: 'All suppliers', value: 'all' }],
        },
        filters: {
            direction: 'asc',
            page: 1,
            per_page: 15,
            sort: 'paid_at',
        },
        items: [stalled, unplaced, healthy],
        locale: 'en',
        logoutUrl: '/logout',
        orderUrlTemplate: '/admin/orders/__ID__',
        pagination: {
            currentPage: 1,
            from: 1,
            lastPage: 1,
            perPage: 15,
            to: 3,
            total: 3,
        },
        permissions: [
            'fulfillment.view',
            'fulfillment.view_cost',
            'fulfillment.act',
        ],
        resendUrlTemplate: '/admin/api/fulfillment/__ID__/resend',
        ...overrides,
    };
}

function renderPage(overrides: Partial<AdminFulfillmentPageProps> = {}) {
    pageState.props = baseProps(overrides);

    return render(<AdminFulfillmentPage />);
}

/** The desktop table, which is the `region` labelled for the queue. */
function table(): HTMLElement {
    return screen.getByRole('region', { name: 'Items in fulfillment' });
}

beforeEach(() => {
    vi.clearAllMocks();
    vi.useFakeTimers({ shouldAdvanceTime: true });
    // Fixed, so every relative age in these assertions is deterministic.
    vi.setSystemTime(new Date('2026-09-17T14:40:00Z'));
});

afterEach(() => {
    vi.useRealTimers();
    cleanup();
});

describe('the list', () => {
    it('shows every column an Admin is entitled to', () => {
        renderPage();

        const headers = within(table())
            .getAllByRole('columnheader')
            .map((header) => header.textContent?.trim());

        expect(headers).toEqual([
            'Order',
            'Service',
            'Supplier',
            'Waiting',
            'State',
            'Signal',
            'Cost',
            'Action',
        ]);
    });

    it('leads a row with its alarm rather than its presentation', () => {
        renderPage();

        const row = within(table()).getByText('AUT-1033').closest('tr');

        expect(row).not.toBeNull();
        // The supplier last said `interrupted`, which would read as "Stopped".
        // The open alarm outranks it: an alarm is the only thing on this screen
        // worth waking somebody for.
        expect(within(row as HTMLElement).getByText('Stalled')).toBeTruthy();
        expect(
            within(row as HTMLElement).getByText('interrupted'),
        ).toBeTruthy();
    });

    it('prints time since paid as the bold age, with the supplier age under it', () => {
        renderPage();

        const row = within(table())
            .getByText('AUT-1033')
            .closest('tr') as HTMLElement;

        // Paid 09:34, now 14:40.
        expect(within(row).getByText('5h 06m')).toBeTruthy();
        expect(within(row).getByText('at supplier 5h 00m')).toBeTruthy();
    });

    it('says never placed rather than inventing a supplier age', () => {
        renderPage();

        const row = within(table())
            .getByText('AUT-1042')
            .closest('tr') as HTMLElement;

        expect(within(row).getByText('never placed')).toBeTruthy();
        expect(within(row).getByText('No fulfillment job')).toBeTruthy();
        expect(within(row).getByText('no reading')).toBeTruthy();
    });

    it('renders a missing cost as not reported and never as zero', () => {
        renderPage({ items: [{ ...healthy, cost: null }] });

        expect(within(table()).getByText('not reported')).toBeTruthy();
        expect(within(table()).queryByText('SAR 0.00')).toBeNull();
    });

    it('formats a cost it does have', () => {
        renderPage({ items: [healthy] });

        expect(within(table()).getByText('SAR 118.40')).toBeTruthy();
    });
});

describe('the Staff view', () => {
    it('drops the cost and action columns entirely', () => {
        renderPage({ canAct: false, canSeeCost: false });

        const headers = within(table())
            .getAllByRole('columnheader')
            .map((header) => header.textContent?.trim());

        expect(headers).toEqual([
            'Order',
            'Service',
            'Supplier',
            'Waiting',
            'State',
            'Signal',
        ]);
        expect(headers).not.toContain('Cost');
        // Removed rather than rendered disabled: a disabled control invites a
        // support question about a permission the operator does not have.
        expect(headers).not.toContain('Action');
    });

    it('offers no send button even on the unplaced row', () => {
        renderPage({ canAct: false, canSeeCost: false, items: [unplaced] });

        expect(screen.queryByRole('button', { name: /Send/ })).toBeNull();
    });

    it('hides the refresh control, which reads suppliers', () => {
        renderPage({ canAct: false, canSeeCost: false });

        expect(screen.queryByRole('button', { name: 'Refresh' })).toBeNull();
    });
});

describe('the empty state', () => {
    it('says nothing is owed, with no reset, when no filter is on', () => {
        renderPage({ items: [] });

        // Twice on purpose: the desktop table and the phone card list each
        // carry the shared empty state, the way /admin/orders does.
        expect(
            screen.getAllByText('No supplier owes anything right now.').length,
        ).toBeGreaterThan(0);
        expect(
            screen.queryByRole('button', { name: 'Reset filters' }),
        ).toBeNull();
    });

    it('offers a reset when a filter is what emptied it', () => {
        renderPage({
            filters: {
                alarm: 'silent',
                direction: 'asc',
                page: 1,
                per_page: 15,
                sort: 'paid_at',
            },
            items: [],
        });

        expect(
            screen.getAllByText('No items match these filters.').length,
        ).toBeGreaterThan(0);
        expect(
            screen.getAllByRole('button', { name: 'Reset filters' }).length,
        ).toBeGreaterThan(0);
    });
});

describe('the confirm dialog', () => {
    it('names the order and warns that the request carries credentials', async () => {
        const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime });
        renderPage({ items: [unplaced] });

        await user.click(within(table()).getByRole('button', { name: 'Send' }));

        const dialog = screen.getByRole('dialog');

        expect(
            within(dialog).getByText('Send AUT-1042 to a supplier'),
        ).toBeTruthy();
        expect(
            within(dialog).getByText(/decrypted for this send only/),
        ).toBeTruthy();
        // No promise of a password prompt: nothing in the Admin implements one.
        expect(within(dialog).queryByText(/password/i)).toBeNull();
    });

    it('will not submit without a reason code', async () => {
        const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime });
        renderPage({ items: [unplaced] });

        await user.click(within(table()).getByRole('button', { name: 'Send' }));
        await user.click(
            within(screen.getByRole('dialog')).getByRole('button', {
                name: 'Send to supplier',
            }),
        );

        expect(screen.getByRole('alert').textContent).toContain(
            'Choose a reason before sending.',
        );
        expect(http.submit).not.toHaveBeenCalled();
    });

    it('sends the chosen reason code to the item’s own URL', async () => {
        const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime });
        renderPage({ items: [unplaced] });

        await user.click(within(table()).getByRole('button', { name: 'Send' }));

        const dialog = screen.getByRole('dialog');
        await user.click(within(dialog).getByRole('combobox'));
        await user.click(
            await screen.findByRole('option', {
                name: 'Callback never arrived',
            }),
        );
        await user.click(
            within(dialog).getByRole('button', { name: 'Send to supplier' }),
        );

        expect(http.submit).toHaveBeenCalledTimes(1);

        const [method, target, options] = http.submit.mock.calls[0];

        expect(method).toBe('post');
        expect(target).toBe('/admin/api/fulfillment/item-unplaced/resend');
        // The payload rides on the hook rather than the submit call, the way
        // every other admin dialog sends one.
        expect(http.setData).toHaveBeenCalledWith({
            action: 'send',
            reason_code: 'callback_lost',
        });
        expect(options.headers).toEqual({ Accept: 'application/json' });
    });

    it('will not retry a challenge without saying which solve', async () => {
        const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime });
        renderPage({ items: [multiSolve] });

        await user.click(
            within(table()).getByRole('button', { name: 'Retry' }),
        );

        const dialog = screen.getByRole('dialog');
        const [reason, solve] = within(dialog).getAllByRole('combobox');

        await user.click(reason);
        await user.click(
            await screen.findByRole('option', {
                name: 'Supplier stopped reporting',
            }),
        );
        await user.click(
            within(dialog).getByRole('button', { name: 'Retry challenge' }),
        );

        // A default of zero would retry the first solve, which on this row is
        // the one that already finished.
        expect(screen.getByRole('alert').textContent).toContain(
            'Choose which solve to retry.',
        );
        expect(http.submit).not.toHaveBeenCalled();

        await user.click(solve);
        await user.click(
            await screen.findByRole('option', { name: 'Solve 3' }),
        );
        await user.click(
            within(dialog).getByRole('button', { name: 'Retry challenge' }),
        );

        expect(http.setData).toHaveBeenCalledWith({
            action: 'retry_challenge',
            challenge_position: 2,
            reason_code: 'supplier_stalled',
        });
    });

    it('asks nothing about solves when the placement holds one', async () => {
        const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime });
        renderPage({ items: [unplaced] });

        await user.click(within(table()).getByRole('button', { name: 'Send' }));

        expect(
            within(screen.getByRole('dialog')).getAllByRole('combobox'),
        ).toHaveLength(1);
    });

    it('warns that an unreported purchase cannot be seen from here', async () => {
        const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime });
        renderPage({ items: [unplaced] });

        await user.click(within(table()).getByRole('button', { name: 'Send' }));

        expect(
            within(screen.getByRole('dialog')).getByText(
                /buy the same coins twice/,
            ),
        ).toBeTruthy();
    });

    it('says resume rather than send for a placed item', async () => {
        const user = userEvent.setup({ advanceTimers: vi.advanceTimersByTime });
        renderPage({ items: [stalled] });

        await user.click(
            within(table()).getByRole('button', { name: 'Resume' }),
        );

        const dialog = screen.getByRole('dialog');

        expect(
            within(dialog).getByText('Resume AUT-1033 at the supplier'),
        ).toBeTruthy();
        expect(within(dialog).getByText(/places nothing new/)).toBeTruthy();
        // A placed item's request carries no freshly composed credentials.
        expect(
            within(dialog).queryByText(/decrypted for this send only/),
        ).toBeNull();
    });
});
