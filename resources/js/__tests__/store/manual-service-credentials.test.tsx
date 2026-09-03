import {
    cleanup,
    fireEvent,
    render,
    screen,
    within,
} from '@testing-library/react';
import { useState } from 'react';
import { afterEach, expect, it } from 'vitest';

import { CredentialsFields } from '@/components/configurator/manual-services/credentials-fields';
import { validateManualFieldOnBlur } from '@/components/configurator/manual-services/form-utils';
import type { ManualFormErrors } from '@/components/configurator/manual-services/form-utils';
import type {
    ManualCredentialsDraft,
    ManualServiceCommonTranslations,
} from '@/types/manual-services';
import { emptyManualCredentials } from '@/types/manual-services';

afterEach(cleanup);

function Harness({ platform }: { platform: 'pc' | 'playstation' }) {
    const [credentials, setCredentials] = useState<ManualCredentialsDraft>(
        emptyManualCredentials,
    );
    const [errors, setErrors] = useState<ManualFormErrors>({});

    function handleBlur(field: string, value: string) {
        const error = validateManualFieldOnBlur(
            field,
            value,
            platform,
            platform === 'pc' ? 'steam' : null,
            credentials,
            translations,
        );
        setErrors((prev) => {
            if (error) {
                return { ...prev, [field]: error };
            }

            const next = { ...prev };
            delete next[field];

            return next;
        });
    }

    return (
        <CredentialsFields
            credentials={credentials}
            errors={errors}
            launcher={platform === 'pc' ? 'steam' : null}
            onBlurField={handleBlur}
            onChange={setCredentials}
            platform={platform}
            translations={translations}
            tutorials={{
                ea: 'https://youtube.com/shorts/hNIW1ps_t3k?si=i9MR5izDKRhpRNjo',
                playstation: 'https://youtu.be/fCAKsusuHR8?si=cYzL6fwszL4ExwPK',
            }}
        />
    );
}

it('shows the exact PlayStation credential shape and normalizes Sony codes', () => {
    render(<Harness platform="playstation" />);

    expect(screen.getByLabelText('PlayStation email')).toBeVisible();
    expect(screen.getByLabelText('PlayStation password')).toHaveAttribute(
        'type',
        'password',
    );
    expect(screen.queryByLabelText('EA email')).not.toBeInTheDocument();
    expect(screen.queryByLabelText('EA password')).not.toBeInTheDocument();
    expect(screen.getAllByLabelText(/Backup code/)).toHaveLength(6);
    const eaCodes = screen.getByRole('group', { name: 'EA codes' });
    const playstationCodes = screen.getByRole('group', {
        name: 'PlayStation codes',
    });
    expect(
        within(eaCodes).getByRole('link', { name: /EA tutorial/ }),
    ).toHaveAttribute(
        'href',
        'https://youtube.com/shorts/hNIW1ps_t3k?si=i9MR5izDKRhpRNjo',
    );
    expect(
        within(playstationCodes).getByRole('link', {
            name: /PlayStation tutorial/,
        }),
    ).toHaveAttribute(
        'href',
        'https://youtu.be/fCAKsusuHR8?si=cYzL6fwszL4ExwPK',
    );
    expect(
        screen.queryByRole('navigation', { name: 'Tutorials' }),
    ).not.toBeInTheDocument();

    fireEvent.click(screen.getByRole('button', { name: 'Show password' }));
    expect(screen.getByLabelText('PlayStation password')).toHaveAttribute(
        'type',
        'text',
    );
    const sonyCode = screen.getAllByLabelText(/Backup code/)[3];
    fireEvent.change(sonyCode, { target: { value: 'a1-b2c3!' } });
    expect(sonyCode).toHaveValue('A1B2C3');
});

it('shows EA and Steam credentials on PC without Sony fields or codes', () => {
    render(<Harness platform="pc" />);

    expect(screen.getByLabelText('EA email')).toBeVisible();
    expect(screen.getByLabelText('EA password')).toBeVisible();
    expect(screen.getByLabelText('Steam username')).toBeVisible();
    expect(screen.getByLabelText('Steam password')).toBeVisible();
    expect(
        screen.queryByLabelText('PlayStation email'),
    ).not.toBeInTheDocument();
    expect(screen.getAllByLabelText(/Backup code/)).toHaveLength(3);
    expect(
        screen.queryByRole('link', { name: /PlayStation tutorial/ }),
    ).not.toBeInTheDocument();
});

it('validates invalid email on blur and clears it when fixed', () => {
    render(<Harness platform="playstation" />);

    const emailInput = screen.getByLabelText('PlayStation email');
    fireEvent.change(emailInput, { target: { value: 'not-an-email' } });
    fireEvent.blur(emailInput);

    expect(screen.getByText('Invalid email')).toBeVisible();
    expect(emailInput).toHaveAttribute('aria-invalid', 'true');

    fireEvent.change(emailInput, { target: { value: 'user@example.test' } });
    fireEvent.blur(emailInput);

    expect(screen.queryByText('Invalid email')).not.toBeInTheDocument();
    expect(emailInput).toHaveAttribute('aria-invalid', 'false');
});

it('flags duplicate backup codes on blur', () => {
    render(<Harness platform="playstation" />);

    const codes = screen.getAllByLabelText(/Backup code/);
    fireEvent.change(codes[0], { target: { value: '12345678' } });
    fireEvent.change(codes[1], { target: { value: '12345678' } });
    fireEvent.blur(codes[1]);

    expect(screen.getByText('Duplicate codes')).toBeVisible();
});

const translations: ManualServiceCommonTranslations = {
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
    invalid_ea_code: 'Invalid EA code',
    invalid_playstation_code: 'Invalid PS code',
    duplicate_codes: 'Duplicate codes',
    image_required: 'Image required',
    image_invalid: 'Invalid image',
    image_too_large: 'Image too large',
    step_platform: '1. Platform',
    step_options: '2. Options',
    step_account: '3. Account',
    step_image: '4. Squad image',
    panel_title: 'Order summary',
    eta_label: 'Estimated delivery',
    squad_image_choose: 'Choose image',
    see_all_sbc: 'All SBC challenges',
    tab_options: 'Options',
    tab_guide: 'How it works',
};
