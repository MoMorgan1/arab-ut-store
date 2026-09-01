import {
    cleanup,
    fireEvent,
    render,
    screen,
    waitFor,
    within,
} from '@testing-library/react';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';

import { FutChampionsConfigurator } from '@/components/configurator/manual-services/fut-champions-configurator';
import type {
    FutServiceTranslations,
    ManualServiceCommonTranslations,
} from '@/types/manual-services';

const fetchMock = vi.fn();

beforeEach(() => {
    window.history.pushState({}, '', '/fut-champions');
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
    window.history.pushState({}, '', '/');
    document.querySelector('meta[name="csrf-token"]')?.remove();
    vi.restoreAllMocks();
    vi.unstubAllGlobals();
});

it('renders the platform choice cards with decorative logo images', () => {
    renderFut();

    const psRadio = screen.getByRole('radio', { name: 'PlayStation' });
    expect(psRadio).toBeInTheDocument();
    const psCard = psRadio.closest('label')!;
    const logoImg = psCard.querySelector('img');
    expect(logoImg).toHaveAttribute(
        'src',
        '/images/store/platforms/ps-logo-white-80.webp',
    );
    expect(logoImg).toHaveAttribute('alt', '');
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
    expect(rankSlider).toHaveValue('1');
    expect(rankSlider.getAttribute('aria-valuetext')).toContain('Rank 1');
    expect(rankSlider.getAttribute('aria-valuetext')).toContain('220.00');
});

it('keeps the active delivery estimate inside the urgent option and omits the repeated played-matches note', () => {
    renderFut();

    const urgentCheckbox = screen.getByRole('checkbox', { name: /Urgent/ });
    const urgentOption = urgentCheckbox.closest('label')!;
    expect(within(urgentOption).getByText('Current FUT event')).toBeVisible();
    expect(
        screen.queryByText('Played matches are accepted'),
    ).not.toBeInTheDocument();

    fireEvent.click(urgentCheckbox);

    expect(
        within(urgentOption).getByText('Urgent orders take 24–36 hours'),
    ).toBeVisible();
    expect(
        within(urgentOption).queryByText('Current FUT event'),
    ).not.toBeInTheDocument();
});

it('submits the selected FUT rank, urgent option, exact credentials, and required image once', async () => {
    renderFut();

    const rankSlider = screen.getByRole('slider', { name: 'Target rank' });
    fireEvent.change(rankSlider, { target: { value: '1' } });
    fireEvent.click(screen.getByRole('checkbox', { name: /Urgent/ }));
    fireEvent.click(screen.getByLabelText('Yes, I played matches'));
    fireEvent.change(screen.getByLabelText('Matches already played'), {
        target: { value: '4' },
    });
    fireEvent.change(screen.getByLabelText('PlayStation email'), {
        target: { value: 'buyer@example.test' },
    });
    fireEvent.change(screen.getByLabelText('PlayStation password'), {
        target: { value: 'hunter2' },
    });
    const codes = screen.getAllByLabelText(/Backup code/);
    ['11111111', '22222222', '33333333', 'ABC123', 'DEF456', 'GHI789'].forEach(
        (code, idx) =>
            fireEvent.change(codes[idx], { target: { value: code } }),
    );
    const image = new File(['image-bytes'], 'squad.png', { type: 'image/png' });
    const fileInput = document.querySelector(
        'input[name="squad-image"]',
    ) as HTMLInputElement;
    fireEvent.change(fileInput, {
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
        'buyer@example.test',
    );
    expect(form.get('credentials[playstation_backup_codes][0]')).toBe('ABC123');
    expect(form.get('squadImage')).toBe(image);
});

it('validates played matches count when yes is selected', () => {
    renderFut();

    fireEvent.click(screen.getByLabelText('Yes, I played matches'));
    const matchesInput = screen.getByLabelText('Matches already played');
    fireEvent.change(matchesInput, { target: { value: '0' } });
    fireEvent.blur(matchesInput);

    expect(screen.getByText('Required')).toBeVisible();

    fireEvent.change(matchesInput, { target: { value: '3' } });
    fireEvent.blur(matchesInput);

    expect(screen.queryByText('Required')).not.toBeInTheDocument();
});

it('focuses the first invalid field when submit fails with validation errors', async () => {
    renderFut();

    fireEvent.submit(
        screen
            .getByRole('button', { name: 'Add service to cart' })
            .closest('form')!,
    );

    await waitFor(() => {
        expect(screen.getByLabelText('PlayStation email')).toHaveFocus();
    });
});

it('validates invalid email on blur and clears error on fix', () => {
    renderFut();

    const emailInput = screen.getByLabelText('PlayStation email');
    fireEvent.change(emailInput, { target: { value: 'bad-email' } });
    fireEvent.blur(emailInput);

    expect(screen.getByText('Invalid email')).toBeVisible();

    fireEvent.change(emailInput, { target: { value: 'good@example.test' } });
    fireEvent.blur(emailInput);

    expect(screen.queryByText('Invalid email')).not.toBeInTheDocument();
});

function renderFut() {
    render(
        <FutChampionsConfigurator
            addUrl="/cart/items/fut-champions"
            common={common}
            locale="en"
            pricing={{
                currency: 'SAR',
                rankOptions: [
                    { rank: 1, price: { amountMinor: 22000, currency: 'SAR' } },
                    { rank: 2, price: { amountMinor: 19000, currency: 'SAR' } },
                    { rank: 3, price: { amountMinor: 17000, currency: 'SAR' } },
                    { rank: 4, price: { amountMinor: 15000, currency: 'SAR' } },
                    { rank: 5, price: { amountMinor: 13000, currency: 'SAR' } },
                    { rank: 6, price: { amountMinor: 11000, currency: 'SAR' } },
                ],
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

const common: ManualServiceCommonTranslations = {
    back: 'Back',
    platform_legend: 'Choose platform',
    platforms: { playstation: 'PlayStation', pc: 'PC' },
    platform_captions: {
        playstation: 'PS4 and PS5',
        pc: 'EA app or Steam',
    },
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
    step_platform: '1. Choose platform',
    step_options: '2. Service options',
    step_account: '3. Account details',
    step_image: '4. Squad image',
    panel_title: 'Order summary',
    eta_label: 'Estimated delivery',
    squad_image_choose: 'Choose image',
    see_all_sbc: 'All SBC challenges',
};

const fut: FutServiceTranslations = {
    eyebrow: 'Competitive',
    title: 'FUT',
    intro: 'Intro',
    target_legend: 'Target rank',
    rank: 'Rank :rank',
    urgent: 'Urgent',
    urgent_price: 'Add SAR 40',
    urgent_eta: 'Urgent orders take 24–36 hours',
    standard_eta: 'Current FUT event',
    matches_question: 'Have you played any FUT matches?',
    matches_yes: 'Yes, I played matches',
    matches_no: 'No matches played',
    matches_played: 'Matches already played',
    notes: { timing: '', details: '', login: '', shortfall: '', safety: '' },
};
