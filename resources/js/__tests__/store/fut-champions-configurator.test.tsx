import {
    cleanup,
    fireEvent,
    render,
    screen,
    waitFor,
} from '@testing-library/react';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';

import { FutChampionsConfigurator } from '@/components/configurator/manual-services/fut-champions-configurator';
import type {
    FutServiceTranslations,
    ManualServiceCommonTranslations,
} from '@/types/manual-services';

const fetchMock = vi.fn();

beforeEach(() => {
    fetchMock.mockReset().mockResolvedValue(
        new Response(
            JSON.stringify({
                data: {
                    cartCount: 1,
                    cartItemId: '01K00000000000000000000000',
                    cartUrl: '/cart',
                },
            }),
            { status: 201 },
        ),
    );
    const csrf = document.createElement('meta');
    csrf.name = 'csrf-token';
    csrf.content = 'test-token';
    document.head.append(csrf);
    vi.stubGlobal('fetch', fetchMock);
    vi.stubGlobal('crypto', { randomUUID: () => 'attempt-key' });
    vi.spyOn(URL, 'createObjectURL').mockReturnValue('blob:preview');
    vi.spyOn(URL, 'revokeObjectURL').mockImplementation(() => undefined);
});

afterEach(() => {
    cleanup();
    document.querySelector('meta[name="csrf-token"]')?.remove();
    vi.restoreAllMocks();
    vi.unstubAllGlobals();
});

it('uses a rank slider and only asks for a match count after the customer confirms they played', () => {
    renderFut();

    const rankSlider = screen.getByRole('slider', { name: 'Target rank' });
    expect(rankSlider).toHaveValue('3');
    expect(rankSlider.getAttribute('aria-valuetext')).toContain('Rank 3');
    expect(rankSlider.getAttribute('aria-valuetext')).toContain('170.00');
    expect(
        screen.queryByLabelText('Matches already played'),
    ).not.toBeInTheDocument();

    fireEvent.click(screen.getByLabelText('Yes, I played matches'));
    expect(screen.getByLabelText('Matches already played')).toBeVisible();

    fireEvent.change(rankSlider, { target: { value: '1' } });
    expect(rankSlider.getAttribute('aria-valuetext')).toContain('Rank 1');
    expect(rankSlider.getAttribute('aria-valuetext')).toContain('220.00');
});

it('submits the selected FUT rank, urgent option, exact credentials, and required image once', async () => {
    renderFut();

    fireEvent.change(screen.getByRole('slider', { name: 'Target rank' }), {
        target: { value: '1' },
    });
    fireEvent.click(screen.getByLabelText(/Urgent/));
    fireEvent.click(screen.getByLabelText('Yes, I played matches'));
    fireEvent.change(screen.getByLabelText('Matches already played'), {
        target: { value: '4' },
    });
    fireEvent.change(screen.getByLabelText('PlayStation email'), {
        target: { value: 'owner@example.test' },
    });
    fireEvent.change(screen.getByLabelText('PlayStation password'), {
        target: { value: 'PS secret' },
    });
    const codes = screen.getAllByLabelText(/Backup code/);
    ['12345678', '23456789', '34567890', 'A1B2C3', 'D4E5F6', 'Z9Y8X7'].forEach(
        (value, index) => fireEvent.change(codes[index], { target: { value } }),
    );
    const image = new File(['squad'], 'squad.png', { type: 'image/png' });
    fireEvent.change(screen.getByLabelText(/Squad image/), {
        target: { files: [image] },
    });
    fireEvent.submit(
        screen
            .getByRole('button', { name: 'Add service to cart' })
            .closest('form')!,
    );

    await waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(1));
    const request = fetchMock.mock.calls[0]?.[1] as RequestInit;
    const form = request.body as FormData;
    expect(form.get('rank')).toBe('1');
    expect(form.get('urgent')).toBe('1');
    expect(form.get('matchesPlayed')).toBe('4');
    expect(form.get('credentials[playstation_email]')).toBe(
        'owner@example.test',
    );
    expect(form.get('credentials[ea_email]')).toBeNull();
    expect(form.get('credentials[playstation_backup_codes][2]')).toBe('Z9Y8X7');
    expect(form.get('squadImage')).toBe(image);
    expect(screen.getByText(/260\.00/)).toBeVisible();
    expect(screen.getByText('Urgent orders take 24–36 hours')).toBeVisible();
    expect(document.body.textContent).not.toContain('PS secret');
});

function renderFut() {
    render(
        <FutChampionsConfigurator
            addUrl="/cart/items/fut-champions"
            common={common}
            locale="en"
            pricing={{
                currency: 'SAR',
                rankOptions: [1, 2, 3, 4, 5, 6].map((rank, index) => ({
                    rank,
                    price: {
                        amountMinor: [22000, 19000, 17000, 15000, 13000, 10000][
                            index
                        ],
                        currency: 'SAR',
                    },
                })),
                urgentSurcharge: { amountMinor: 4000, currency: 'SAR' },
            }}
            product={{
                id: '01K00000000000000000000000',
                slug: 'fut-champions',
                name: 'FUT Champions service',
                description: 'Service description',
                image: { alt: 'FUT', url: '/fut.webp' },
            }}
            scheduleVersion={1}
            service={fut}
            tutorials={{
                ea: 'https://example.test/ea',
                playstation: 'https://example.test/ps',
            }}
        />,
    );
}

const common = {
    back: 'Back',
    platform_legend: 'Choose platform',
    platforms: { playstation: 'PlayStation', pc: 'PC' },
    pc_store_legend: 'Choose launcher',
    pc_stores: { ea_app: 'EA app', steam: 'Steam' },
    account_details_title: 'Account details',
    ea_email: 'EA email',
    ea_password: 'EA password',
    steam_username: 'Steam username',
    steam_password: 'Steam password',
    playstation_email: 'PlayStation email',
    playstation_password: 'PlayStation password',
    show_password: 'Show password',
    hide_password: 'Hide password',
    ea_codes: 'EA codes',
    ea_codes_help: 'Three EA codes',
    playstation_codes: 'PlayStation codes',
    playstation_codes_help: 'Three PlayStation codes',
    backup_code: 'Backup code :number',
    squad_image: 'Squad image',
    squad_image_help: 'PNG up to 5MB',
    squad_image_remove: 'Remove image',
    ea_tutorial: 'EA tutorial',
    playstation_tutorial: 'PlayStation tutorial',
    notes_title: 'Notes',
    add_to_cart: 'Add service to cart',
    adding: 'Adding…',
    added: 'Added',
    add_error: 'Error',
    unavailable_title: 'Unavailable',
    unavailable_body: 'Try later',
    review_title: 'Review your service',
    review_service: 'Service',
    review_platform: 'Platform',
    review_launcher: 'Launcher',
    review_total: 'Total',
    review_credentials: 'Credentials',
    review_credentials_ready: 'Details sent securely',
    review_image_ready: 'Image ready',
    required_field: 'Required',
    invalid_email: 'Invalid email',
    invalid_ea_code: 'Invalid code',
    invalid_playstation_code: 'Invalid PS code',
    duplicate_codes: 'Different codes',
    image_required: 'Image required',
    image_invalid: 'Invalid image',
    image_too_large: 'Image too large',
} satisfies ManualServiceCommonTranslations;

const fut = {
    eyebrow: 'Competitive',
    title: 'FUT',
    intro: 'Intro',
    target_legend: 'Target rank',
    rank: 'Rank :rank',
    urgent: 'Urgent',
    urgent_price: 'Add SAR 40',
    urgent_eta: 'Urgent orders take 24–36 hours',
    standard_eta: 'Current FUT event',
    already_played: 'Played matches are accepted',
    matches_question: 'Have you played any FUT matches?',
    matches_yes: 'Yes, I played matches',
    matches_no: 'No matches played',
    matches_played: 'Matches already played',
    notes: { timing: '', details: '', login: '', shortfall: '', safety: '' },
} satisfies FutServiceTranslations;
