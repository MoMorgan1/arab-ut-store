import {
    cleanup,
    fireEvent,
    render,
    screen,
    waitFor,
} from '@testing-library/react';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';

import { RivalsConfigurator } from '@/components/configurator/manual-services/rivals-configurator';
import type {
    ManualServiceCommonTranslations,
    RivalsServiceTranslations,
} from '@/types/manual-services';

const PS_VARIANT_ID = '01K00000000000000000000001';
const PC_VARIANT_ID = '01K00000000000000000000002';
const REPLACE_ID = '01K00000000000000000000000';

const page = vi.hoisted(() => ({
    props: {
        cartVariantIds: [] as string[],
        storeShell: { cartUrl: '/cart' },
    },
    url: '/rivals',
}));

vi.mock('@inertiajs/react', () => ({
    usePage: () => page,
}));

const fetchMock = vi.fn();

beforeEach(() => {
    window.history.pushState({}, '', '/rivals');
    page.props.cartVariantIds = [];
    page.url = '/rivals';
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
    vi.stubGlobal('crypto', { randomUUID: () => 'rivals-key' });
    vi.spyOn(URL, 'createObjectURL').mockReturnValue('blob:rivals');
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
    renderRivals();

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

it('updates the available Rivals route and price as the sliders move', () => {
    renderRivals();

    const currentSlider = screen.getByRole('slider', {
        name: 'Current division',
    });
    const targetSlider = screen.getByRole('slider', {
        name: 'Target division',
    });

    expect(currentSlider).toHaveValue('2');
    expect(targetSlider).toHaveValue('7');
    expect(currentSlider.getAttribute('aria-valuetext')).toBe('Division 5');
    expect(targetSlider.getAttribute('aria-valuetext')).toContain('Elite');
    expect(targetSlider.getAttribute('aria-valuetext')).toContain('750.00');

    fireEvent.change(currentSlider, { target: { value: '0' } });
    fireEvent.change(targetSlider, { target: { value: '1' } });

    expect(currentSlider).toHaveValue('0');
    expect(targetSlider).toHaveValue('1');
    expect(currentSlider.getAttribute('aria-valuetext')).toBe('Division 7');
    expect(targetSlider.getAttribute('aria-valuetext')).toContain('Division 6');
    expect(targetSlider.getAttribute('aria-valuetext')).toContain('110.00');
});

it('submits 5 to Elite as SAR 750 and never sends an urgent field', async () => {
    renderRivals();

    const currentSlider = screen.getByRole('slider', {
        name: 'Current division',
    });
    const targetSlider = screen.getByRole('slider', {
        name: 'Target division',
    });
    expect(currentSlider.getAttribute('aria-valuetext')).toBe('Division 5');
    expect(targetSlider.getAttribute('aria-valuetext')).toContain('Elite');
    expect(targetSlider.getAttribute('aria-valuetext')).toContain('750.00');

    expect(screen.queryByRole('checkbox')).not.toBeInTheDocument();
    expect(
        screen.queryByText('No urgent service is available'),
    ).not.toBeInTheDocument();

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
    expect(form.get('mode')).toBe('promotion');
    expect(form.get('currentDivision')).toBe('5');
    expect(form.get('targetDivision')).toBe('elite');
    expect(form.get('urgent')).toBeNull();
    expect(form.get('credentials[playstation_email]')).toBe(
        'owner@example.test',
    );
    expect(form.get('credentials[ea_backup_codes][0]')).toBe('12345678');
    expect(form.get('credentials[playstation_backup_codes][1]')).toBe('D4E5F6');
    expect(form.get('squadImage')).toBe(image);
    expect(screen.getAllByText(/750\.00/)[0]).toBeVisible();
    expect(document.body.textContent).not.toContain('PS secret');
});

it('prefills valid route from URL query parameters', () => {
    window.history.pushState(
        {},
        '',
        '/rivals?currentDivision=6&targetDivision=3',
    );
    renderRivals();

    const currentSlider = screen.getByRole('slider', {
        name: 'Current division',
    });
    const targetSlider = screen.getByRole('slider', {
        name: 'Target division',
    });

    expect(currentSlider).toHaveValue('1');
    expect(targetSlider).toHaveValue('4');
    expect(currentSlider.getAttribute('aria-valuetext')).toBe('Division 6');
    expect(targetSlider.getAttribute('aria-valuetext')).toContain('Division 3');
    expect(targetSlider.getAttribute('aria-valuetext')).toContain('390.00');
});

it('degrades invalid route to default 5 to Elite while leaving controls editable', () => {
    window.history.pushState(
        {},
        '',
        '/rivals?currentDivision=1&targetDivision=7',
    );
    renderRivals();

    const currentSlider = screen.getByRole('slider', {
        name: 'Current division',
    });
    const targetSlider = screen.getByRole('slider', {
        name: 'Target division',
    });

    expect(currentSlider).toHaveValue('2');
    expect(targetSlider).toHaveValue('7');
    expect(currentSlider.getAttribute('aria-valuetext')).toBe('Division 5');
    expect(targetSlider.getAttribute('aria-valuetext')).toContain('Elite');
});

it('never prefills secret credential fields from URL parameters', () => {
    window.history.pushState(
        {},
        '',
        '/rivals?playstation_email=hacker@evil.test&playstation_password=secret&ea_password=secret',
    );
    renderRivals();

    expect(screen.getByLabelText('PlayStation email')).toHaveValue('');
    expect(screen.getByLabelText('PlayStation password')).toHaveValue('');
    expect(document.body.textContent).not.toContain('hacker@evil.test');
    expect(document.body.textContent).not.toContain('secret');
});

it('allows selecting division via interactive stop buttons', () => {
    renderRivals();

    const targetDiv3Button = screen.getByRole('button', {
        name: 'Target division: 3',
    });
    fireEvent.click(targetDiv3Button);

    const targetSlider = screen.getByRole('slider', {
        name: 'Target division',
    });
    expect(targetSlider).toHaveValue('4');
    expect(targetSlider.getAttribute('aria-valuetext')).toContain('Division 3');
    expect(targetSlider.getAttribute('aria-valuetext')).toContain('270.00');
});

it('swaps between promotion and weekly matches modes correctly', () => {
    renderRivals();

    expect(
        screen.getByRole('slider', { name: 'Current division' }),
    ).toBeInTheDocument();
    expect(
        screen.getByRole('slider', { name: 'Target division' }),
    ).toBeInTheDocument();

    fireEvent.click(screen.getByRole('radio', { name: 'Weekly matches' }));

    expect(
        screen.queryByRole('slider', { name: 'Current division' }),
    ).not.toBeInTheDocument();
    expect(
        screen.queryByRole('slider', { name: 'Target division' }),
    ).not.toBeInTheDocument();
    expect(screen.getAllByText(/250\.00/)[0]).toBeVisible();

    fireEvent.click(screen.getByRole('radio', { name: 'Division promotion' }));

    expect(
        screen.getByRole('slider', { name: 'Current division' }),
    ).toBeInTheDocument();
    expect(
        screen.getByRole('slider', { name: 'Target division' }),
    ).toBeInTheDocument();
});

it('validates invalid email on blur and clears error on fix', () => {
    renderRivals();

    const emailInput = screen.getByLabelText('PlayStation email');
    fireEvent.change(emailInput, { target: { value: 'bad-email' } });
    fireEvent.blur(emailInput);

    expect(screen.getByText('Invalid email')).toBeVisible();

    fireEvent.change(emailInput, { target: { value: 'good@example.test' } });
    fireEvent.blur(emailInput);

    expect(screen.queryByText('Invalid email')).not.toBeInTheDocument();
});

it('focuses the first invalid field when submit fails with validation errors', async () => {
    renderRivals();

    fireEvent.submit(
        screen
            .getByRole('button', { name: 'Add service to cart' })
            .closest('form')!,
    );

    await waitFor(() => {
        expect(screen.getByLabelText('PlayStation email')).toHaveFocus();
    });
});

it('swaps credentials fields when switching platforms', () => {
    renderRivals();

    expect(screen.getByLabelText('PlayStation email')).toBeVisible();
    expect(screen.queryByLabelText('EA email')).not.toBeInTheDocument();

    fireEvent.click(screen.getByRole('radio', { name: 'PC' }));
    expect(
        screen.queryByLabelText('PlayStation email'),
    ).not.toBeInTheDocument();
    expect(screen.getByLabelText('EA email')).toBeVisible();

    fireEvent.click(screen.getByRole('radio', { name: 'PlayStation' }));
    expect(screen.getByLabelText('PlayStation email')).toBeVisible();
    expect(screen.queryByLabelText('EA email')).not.toBeInTheDocument();
});

it('shows the in-cart state up front when the platform variant is in the cart', () => {
    page.props.cartVariantIds = [PS_VARIANT_ID];
    renderRivals();

    expect(screen.getByRole('button', { name: 'In cart' })).toBeDisabled();
    expect(screen.getByRole('link', { name: 'Open cart' })).toHaveAttribute(
        'href',
        '/cart',
    );

    fireEvent.click(screen.getByRole('radio', { name: 'PC' }));
    fireEvent.click(screen.getByRole('radio', { name: 'EA app' }));

    expect(
        screen.getByRole('button', { name: 'Add service to cart' }),
    ).toBeEnabled();
    expect(
        screen.queryByRole('link', { name: 'Open cart' }),
    ).not.toBeInTheDocument();
});

it('prefills the edit URL, keeps the squad image, and sends the replaced line id', async () => {
    window.history.pushState(
        {},
        '',
        `/rivals?platform=pc&launcher=steam&from=6&to=3&mode=route&replace=${REPLACE_ID}`,
    );
    vi.stubGlobal(
        'fetch',
        vi.fn((url: string, init?: RequestInit) => {
            const isPost = (init?.method ?? 'GET') === 'POST';

            return Promise.resolve(
                new Response(
                    isPost
                        ? JSON.stringify({
                              data: {
                                  cartCount: 1,
                                  cartItemId: '01K00000000000000000000005',
                                  cartUrl: '/cart',
                              },
                          })
                        : JSON.stringify({
                              data: {
                                  platform: 'pc',
                                  launcher: 'steam',
                                  eaEmail: 'owner@example.test',
                                  eaPassword: 'ea secret',
                                  eaCodes: ['12345678', '23456789', '34567890'],
                                  playstationEmail: '',
                                  playstationPassword: '',
                                  playstationCodes: ['', '', ''],
                                  steamUsername: 'SteamPlayer',
                                  steamPassword: 'steam secret',
                              },
                          }),
                    { status: isPost ? 201 : 200 },
                ),
            );
        }),
    );
    renderRivals(`/cart/items/${REPLACE_ID}/credentials`);

    expect(screen.getByText('Editing the order in your cart')).toBeVisible();
    expect(
        screen.getByText(
            'Your current image is kept — upload a new one to change it',
        ),
    ).toBeVisible();
    expect(await screen.findByDisplayValue('owner@example.test')).toBeVisible();
    expect(screen.getByDisplayValue('SteamPlayer')).toBeVisible();

    const codes = screen.getAllByLabelText(/Backup code/);
    expect(codes).toHaveLength(3);

    fireEvent.submit(
        screen
            .getByRole('button', { name: 'Add service to cart' })
            .closest('form')!,
    );

    await waitFor(() => {
        const calls = (fetch as unknown as ReturnType<typeof vi.fn>).mock.calls;
        const post = calls.find(
            (call) => (call[1] as RequestInit)?.method === 'POST',
        );

        expect(post).toBeDefined();

        const form = (post?.[1] as RequestInit).body as FormData;
        expect(form.get('replaceCartItemId')).toBe(REPLACE_ID);
        // No new upload: the old squad image is carried over server-side.
        expect(form.get('squadImage')).toBeNull();
        expect(form.get('platform')).toBe('pc');
        expect(form.get('pcStore')).toBe('steam');
    });

    // The replacement landed, so the editing note is over.
    await waitFor(() =>
        expect(
            screen.queryByText('Editing the order in your cart'),
        ).not.toBeInTheDocument(),
    );
});

function renderRivals(replaceCredentialsUrl: string | null = null) {
    render(
        <RivalsConfigurator
            addUrl="/cart/items/rivals"
            common={common}
            locale="en"
            pricing={{
                currency: 'SAR',
                ladder: ['7', '6', '5', '4', '3', '2', '1', 'elite'],
                stepOptions: [
                    {
                        from: '7',
                        to: '6',
                        price: { amountMinor: 11000, currency: 'SAR' },
                    },
                    {
                        from: '6',
                        to: '5',
                        price: { amountMinor: 12000, currency: 'SAR' },
                    },
                    {
                        from: '5',
                        to: '4',
                        price: { amountMinor: 13000, currency: 'SAR' },
                    },
                    {
                        from: '4',
                        to: '3',
                        price: { amountMinor: 14000, currency: 'SAR' },
                    },
                    {
                        from: '3',
                        to: '2',
                        price: { amountMinor: 15000, currency: 'SAR' },
                    },
                    {
                        from: '2',
                        to: '1',
                        price: { amountMinor: 16000, currency: 'SAR' },
                    },
                    {
                        from: '1',
                        to: 'elite',
                        price: { amountMinor: 17000, currency: 'SAR' },
                    },
                ],
                weeklyMatches: {
                    price: { amountMinor: 25000, currency: 'SAR' },
                    includedWins: 7,
                },
            }}
            product={{
                id: '01K00000000000000000000000',
                slug: 'rivals',
                name: 'Rivals service',
                description: 'Service description',
                image: { alt: 'Rivals', url: '/rivals.webp' },
            }}
            replaceCredentialsUrl={replaceCredentialsUrl}
            scheduleVersion={1}
            service={rivals}
            tutorials={{
                ea: 'https://example.test/ea',
                playstation: 'https://example.test/ps',
            }}
            variantIds={{ playstation: PS_VARIANT_ID, pc: PC_VARIANT_ID }}
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
    squad_image_help: 'WebP up to 5MB',
    squad_image_remove: 'Remove image',
    ea_tutorial: 'EA tutorial',
    playstation_tutorial: 'PlayStation tutorial',
    notes_title: 'Notes',
    add_to_cart: 'Add service to cart',
    adding: 'Adding…',
    added: 'Added',
    add_error: 'Error',
    in_cart: 'In cart',
    open_cart: 'Open cart',
    editing_replace: 'Editing the order in your cart',
    squad_image_kept:
        'Your current image is kept — upload a new one to change it',
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
    tab_options: 'Options',
    tab_guide: 'How it works',
};

const rivals: RivalsServiceTranslations = {
    eyebrow: 'Competitive',
    title: 'Rivals',
    intro: 'Intro',
    current_legend: 'Current division',
    target_legend: 'Target division',
    division: 'Division :division',
    elite: 'Elite',
    mode_legend: 'What would you like us to play?',
    mode_promotion: 'Division promotion',
    mode_promotion_hint: 'We play until you reach the division you want.',
    mode_weekly: 'Weekly matches',
    mode_weekly_hint:
        'We play your week without promoting — :wins wins included.',
    weekly_summary: 'Weekly matches (:wins wins)',
    standard_eta: 'Usually one to three days depending on demand',
    route_summary: 'From Division :from to :to',
    steps_count: ':count divisions',
    notes: { timing: '', login: '', shortfall: '', safety: '' },
};
