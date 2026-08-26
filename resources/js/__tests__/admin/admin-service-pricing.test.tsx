import {
    cleanup,
    fireEvent,
    render,
    screen,
    waitFor,
    within,
} from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import {
    englishAdminUi,
    sampleAdminServicePricingData,
    sampleAdminServicePricingUrls,
} from '@/__tests__/admin/admin-test-fixtures';
import AdminServicePricingSection from '@/components/admin/settings/admin-service-pricing-section';
import type { AdminServicePricingSectionProps } from '@/components/admin/settings/admin-service-pricing-section';

const inertia = vi.hoisted(() => ({
    reload: vi.fn(),
}));

// The section drives its own requests through fetch, but the shared password
// confirm dialog it renders uses useHttp, so the mock has to expose it.
const http = vi.hoisted(() => ({
    data: {} as Record<string, unknown>,
    processing: false,
    setData: vi.fn(),
    submit: vi.fn(),
}));

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    router: {
        reload: inertia.reload,
    },
    useHttp: () => http,
}));

function createSectionProps(
    overrides: Partial<AdminServicePricingSectionProps> = {},
): AdminServicePricingSectionProps {
    return {
        adminUi: englishAdminUi,
        direction: 'ltr',
        locale: 'en',
        servicePricing: sampleAdminServicePricingData,
        servicePricingUrls: sampleAdminServicePricingUrls,
        ...overrides,
    };
}

describe('AdminServicePricingSection', () => {
    beforeEach(() => {
        inertia.reload.mockReset();
        vi.restoreAllMocks();
    });

    afterEach(() => {
        cleanup();
    });

    it('renders FUT Champions and Rivals pricing schedules with formatted SAR prices', () => {
        render(<AdminServicePricingSection {...createSectionProps()} />);

        expect(
            screen.getByRole('heading', { level: 2, name: 'Service pricing' }),
        ).toBeVisible();
        expect(
            screen.getByRole('heading', { level: 3, name: 'FUT Champions' }),
        ).toBeVisible();
        expect(
            screen.getByRole('heading', { level: 3, name: 'Division Rivals' }),
        ).toBeVisible();

        // Check FUT Champions table
        const futTable = screen.getByTestId('pricing-table-fut_champions');
        expect(within(futTable).getByText('Rank 1')).toBeVisible();
        expect(within(futTable).getByText('SAR 220.00')).toBeVisible();
        expect(within(futTable).getByText('Rank 6')).toBeVisible();
        expect(within(futTable).getByText('SAR 100.00')).toBeVisible();
        expect(within(futTable).getByText('Urgent surcharge')).toBeVisible();
        expect(within(futTable).getByText('SAR 40.00')).toBeVisible();

        // Check Rivals table
        const rivalsTable = screen.getByTestId('pricing-table-rivals');
        expect(within(rivalsTable).getByText('Division 7 to 6')).toBeVisible();
        expect(within(rivalsTable).getByText('SAR 110.00')).toBeVisible();
        expect(
            within(rivalsTable).getByText('Division 1 to Elite'),
        ).toBeVisible();
        expect(within(rivalsTable).getByText('SAR 170.00')).toBeVisible();
    });

    it('shows the coins card as quantity bands, not a price ladder', () => {
        render(<AdminServicePricingSection {...createSectionProps()} />);

        const coinsTable = screen.getByTestId('pricing-table-coins');

        // 50,000 is both the floor and the second band step, so assert the row.
        expect(
            within(coinsTable).getByRole('row', {
                name: 'Smallest order 50,000',
            }),
        ).toBeVisible();
        expect(within(coinsTable).getByText('50,000 to 500,000')).toBeVisible();
        expect(
            within(coinsTable).getByText('2,000,000 to 20,000,000'),
        ).toBeVisible();
        expect(within(coinsTable).getByText('Largest order')).toBeVisible();
        expect(within(coinsTable).getByText('Quick amounts')).toBeVisible();
        // What a customer may actually buy, as distinct from where the slider stops.
        expect(
            within(coinsTable).getByRole('row', { name: 'Buying unit 5,000' }),
        ).toBeVisible();

        // Coins carries no money and no division ladder, so neither may leak in.
        expect(within(coinsTable).queryByText(/SAR/)).toBeNull();
        expect(within(coinsTable).queryByText(/Division/)).toBeNull();
    });

    it('offers no availability switch on coins, because turning the row off does not stop coins selling', () => {
        render(<AdminServicePricingSection {...createSectionProps()} />);

        // Two money schedules can be switched off; the coins limits cannot.
        expect(
            screen.getAllByRole('button', { name: /deactivate/i }),
        ).toHaveLength(2);
        expect(
            screen.getByRole('button', { name: 'Edit limits' }),
        ).toBeVisible();
    });

    it('lets an admin move the coins bands and sends them as whole quantities', async () => {
        const fetchMock = vi.fn().mockResolvedValue({
            ok: true,
            status: 200,
            json: async () => ({
                data: {
                    serviceType: 'coins',
                    version: 2,
                    isActive: true,
                    configuration: {},
                    updatedAt: '2026-08-25T00:00:00Z',
                },
            }),
        });
        vi.stubGlobal('fetch', fetchMock);

        render(<AdminServicePricingSection {...createSectionProps()} />);

        fireEvent.click(screen.getByRole('button', { name: 'Edit limits' }));

        expect(
            await screen.findByText('Edit the Coins quantity limits'),
        ).toBeInTheDocument();

        const minimumInput = screen.getByLabelText('Smallest order');
        expect(minimumInput).toHaveValue(50000);
        fireEvent.change(minimumInput, { target: { value: '100000' } });

        fireEvent.click(
            screen.getByRole('button', { name: 'Save price schedule' }),
        );

        await waitFor(() => {
            expect(fetchMock).toHaveBeenCalledWith(
                '/admin/api/settings/service-pricing/coins',
                expect.objectContaining({
                    method: 'POST',
                    body: expect.stringContaining('"minimum":100000'),
                }),
            );
        });

        // The bands travel whole, as integers - never as halalah.
        const sent = JSON.parse(
            (fetchMock.mock.calls[0][1] as { body: string }).body,
        ) as {
            configuration: {
                tiers: Array<{ upTo: number; step: number }>;
                presets: number[];
                roundingUnit: number;
            };
        };

        expect(sent.configuration.tiers).toEqual([
            { upTo: 500000, step: 10000 },
            { upTo: 2000000, step: 50000 },
            { upTo: 20000000, step: 250000 },
        ]);
        expect(sent.configuration.presets).toEqual([
            50000, 100000, 500000, 1000000, 5000000,
        ]);
        expect(sent.configuration.roundingUnit).toBe(5000);
    });

    it('adds and removes a band without touching the others', async () => {
        render(<AdminServicePricingSection {...createSectionProps()} />);

        fireEvent.click(screen.getByRole('button', { name: 'Edit limits' }));
        await screen.findByText('Edit the Coins quantity limits');

        const steps = () => screen.getAllByLabelText(/Band \d+ — Step/);

        expect(steps()).toHaveLength(3);

        fireEvent.click(screen.getByRole('button', { name: 'Add a band' }));
        expect(steps()).toHaveLength(4);

        fireEvent.click(screen.getByRole('button', { name: 'Remove band 1' }));
        expect(steps()).toHaveLength(3);

        // Band 1 is gone, so what was band 2 now leads.
        expect(steps()[0]).toHaveValue(50000);
    });
    it('hides edit and availability controls when servicePricingUrls is null', () => {
        render(
            <AdminServicePricingSection
                {...createSectionProps({ servicePricingUrls: null })}
            />,
        );

        expect(
            screen.queryByRole('button', { name: 'Edit prices' }),
        ).toBeNull();
        expect(
            screen.queryByRole('button', { name: /deactivate/i }),
        ).toBeNull();
    });

    it('allows an admin to open edit dialog and submits FUT Champions prices', async () => {
        const fetchMock = vi.fn().mockResolvedValue({
            ok: true,
            status: 200,
            json: async () => ({
                data: {
                    serviceType: 'fut_champions',
                    version: 2,
                    isActive: true,
                    configuration: {
                        ranks: {
                            '1': 25000,
                            '2': 19000,
                            '3': 17000,
                            '4': 15000,
                            '5': 13000,
                            '6': 10000,
                        },
                        urgent_surcharge_halalah: 5000,
                    },
                    updatedAt: '2026-08-23T00:00:00Z',
                },
            }),
        });
        vi.stubGlobal('fetch', fetchMock);

        render(<AdminServicePricingSection {...createSectionProps()} />);

        const editButtons = screen.getAllByRole('button', {
            name: 'Edit prices',
        });
        fireEvent.click(editButtons[0]); // First is FUT Champions

        expect(
            await screen.findByText('Edit FUT Champions prices'),
        ).toBeInTheDocument();

        // Change rank 1 price to 250.00 SAR (25000 halalah)
        const rank1Input = screen.getByLabelText(/Rank 1/i);
        fireEvent.change(rank1Input, { target: { value: '250.00' } });

        const saveButton = screen.getByRole('button', {
            name: 'Save price schedule',
        });
        fireEvent.click(saveButton);

        await waitFor(() => {
            expect(fetchMock).toHaveBeenCalledWith(
                '/admin/api/settings/service-pricing/fut_champions',
                expect.objectContaining({
                    method: 'POST',
                    body: expect.stringContaining('"expected_version":1'),
                }),
            );
            expect(inertia.reload).toHaveBeenCalledWith({
                only: ['servicePricing'],
            });
        });
    });

    it('handles 409 conflict during price update by displaying conflict alert and reloading', async () => {
        const fetchMock = vi.fn().mockResolvedValue({
            ok: false,
            status: 409,
            json: async () => ({
                serviceType: 'fut_champions',
                version: 2,
                isActive: true,
                configuration: {},
            }),
        });
        vi.stubGlobal('fetch', fetchMock);

        render(<AdminServicePricingSection {...createSectionProps()} />);

        const editButtons = screen.getAllByRole('button', {
            name: 'Edit prices',
        });
        fireEvent.click(editButtons[0]);

        const saveButton = await screen.findByRole('button', {
            name: 'Save price schedule',
        });
        fireEvent.click(saveButton);

        await waitFor(() => {
            expect(
                screen.getByText(
                    'This price schedule was modified by another operator. Please review the latest prices before saving.',
                ),
            ).toBeInTheDocument();
            expect(inertia.reload).toHaveBeenCalledWith({
                only: ['servicePricing'],
            });
        });
    });

    it('handles 422 validation errors and displays inline field errors', async () => {
        const fetchMock = vi.fn().mockResolvedValue({
            ok: false,
            status: 422,
            json: async () => ({
                errors: {
                    'configuration.ranks.1':
                        'The FUT Champions rank price must be a positive integer.',
                },
            }),
        });
        vi.stubGlobal('fetch', fetchMock);

        render(<AdminServicePricingSection {...createSectionProps()} />);

        const editButtons = screen.getAllByRole('button', {
            name: 'Edit prices',
        });
        fireEvent.click(editButtons[0]);

        const saveButton = await screen.findByRole('button', {
            name: 'Save price schedule',
        });
        fireEvent.click(saveButton);

        await waitFor(() => {
            expect(
                screen.getByText(
                    'The FUT Champions rank price must be a positive integer.',
                ),
            ).toBeInTheDocument();
        });
    });

    it('opens availability dialog with clear deactivation copy and submits status change', async () => {
        const fetchMock = vi.fn().mockResolvedValue({
            ok: true,
            status: 200,
            json: async () => ({
                data: {
                    serviceType: 'fut_champions',
                    version: 1,
                    isActive: false,
                    updatedAt: '2026-08-23T00:00:00Z',
                },
            }),
        });
        vi.stubGlobal('fetch', fetchMock);

        render(<AdminServicePricingSection {...createSectionProps()} />);

        const deactivateButtons = screen.getAllByRole('button', {
            name: /deactivate service/i,
        });
        fireEvent.click(deactivateButtons[0]); // FUT Champions

        expect(
            await screen.findByText('Deactivate FUT Champions?'),
        ).toBeInTheDocument();
        expect(
            screen.getByText(
                /Customers will no longer be able to buy this service until it is reactivated\./i,
            ),
        ).toBeInTheDocument();

        const confirmButton = screen.getByRole('button', {
            name: 'Deactivate service',
        });
        fireEvent.click(confirmButton);

        await waitFor(() => {
            expect(fetchMock).toHaveBeenCalledWith(
                '/admin/api/settings/service-pricing/fut_champions/status',
                expect.objectContaining({
                    method: 'POST',
                    body: JSON.stringify({
                        action: 'deactivate',
                        expected_active: true,
                    }),
                }),
            );
            expect(inertia.reload).toHaveBeenCalledWith({
                only: ['servicePricing'],
            });
        });
    });
});
