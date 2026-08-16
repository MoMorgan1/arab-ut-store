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
    vi.stubGlobal('crypto', { randomUUID: () => 'rivals-key' });
    vi.spyOn(URL, 'createObjectURL').mockReturnValue('blob:rivals');
    vi.spyOn(URL, 'revokeObjectURL').mockImplementation(() => undefined);
});

afterEach(() => {
    cleanup();
    document.querySelector('meta[name="csrf-token"]')?.remove();
    vi.restoreAllMocks();
    vi.unstubAllGlobals();
});

it('updates the available Rivals route and price through its division sliders', () => {
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
    expect(screen.getAllByText(/750\.00/)).toHaveLength(2);

    fireEvent.change(currentSlider, { target: { value: '0' } });
    fireEvent.change(targetSlider, { target: { value: '1' } });
    expect(currentSlider.getAttribute('aria-valuetext')).toBe('Division 7');
    expect(targetSlider.getAttribute('aria-valuetext')).toContain('Division 6');
    expect(targetSlider.getAttribute('aria-valuetext')).toContain('110.00');
});

it('submits 5 to Elite as SAR 750 and never sends an urgent field', async () => {
    renderRivals();

    expect(screen.getAllByText(/750\.00/)).toHaveLength(2);
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
    const image = new File(['squad'], 'squad.webp', { type: 'image/webp' });
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
    expect(form.get('currentDivision')).toBe('5');
    expect(form.get('targetDivision')).toBe('elite');
    expect(form.has('urgent')).toBe(false);
    expect(form.get('squadImage')).toBe(image);
    expect(document.body.textContent).not.toContain('PS secret');
});

function renderRivals() {
    render(
        <RivalsConfigurator
            addUrl="/cart/items/rivals"
            common={common}
            locale="en"
            pricing={{
                currency: 'SAR',
                ladder: ['7', '6', '5', '4', '3', '2', '1', 'elite'],
                stepOptions: [
                    ['7', '6', 11000],
                    ['6', '5', 12000],
                    ['5', '4', 13000],
                    ['4', '3', 14000],
                    ['3', '2', 15000],
                    ['2', '1', 16000],
                    ['1', 'elite', 17000],
                ].map(([from, to, amount]) => ({
                    from: String(from) as '7',
                    to: String(to) as '6',
                    price: { amountMinor: Number(amount), currency: 'SAR' },
                })),
            }}
            product={{
                id: '01K00000000000000000000000',
                slug: 'division-rivals',
                name: 'Division Rivals service',
                description: 'Description',
                image: { alt: 'Rivals', url: '/rivals.webp' },
            }}
            scheduleVersion={1}
            service={rivals}
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
    squad_image_help: 'WebP up to 5MB',
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

const rivals = {
    eyebrow: 'Competitive',
    title: 'Rivals',
    intro: 'Intro',
    current_legend: 'Current division',
    target_legend: 'Target division',
    division: 'Division :division',
    elite: 'Elite',
    standard_eta: 'Usually one to three days depending on demand',
    notes: { timing: '', login: '', shortfall: '', safety: '' },
} satisfies RivalsServiceTranslations;
