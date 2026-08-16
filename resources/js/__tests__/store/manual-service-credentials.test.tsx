import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { useState } from 'react';
import { afterEach, expect, it } from 'vitest';

import { CredentialsFields } from '@/components/configurator/manual-services/credentials-fields';
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

    return (
        <CredentialsFields
            credentials={credentials}
            launcher={platform === 'pc' ? 'steam' : null}
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
    expect(screen.getByRole('link', { name: /EA tutorial/ })).toHaveAttribute(
        'href',
        'https://youtube.com/shorts/hNIW1ps_t3k?si=i9MR5izDKRhpRNjo',
    );
    expect(
        screen.getByRole('link', { name: /PlayStation tutorial/ }),
    ).toHaveAttribute(
        'href',
        'https://youtu.be/fCAKsusuHR8?si=cYzL6fwszL4ExwPK',
    );

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

const translations: ManualServiceCommonTranslations = {
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
    tutorials_title: 'Tutorials',
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
    invalid_ea_code: 'Invalid EA code',
    invalid_playstation_code: 'Invalid PS code',
    duplicate_codes: 'Duplicate codes',
    image_required: 'Image required',
    image_invalid: 'Invalid image',
    image_too_large: 'Image too large',
};
