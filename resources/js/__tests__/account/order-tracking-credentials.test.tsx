import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';

import CredentialForm from '@/components/account/order-tracking-credentials';
import type { CredentialStrings } from '@/components/account/order-tracking-credentials';

/**
 * The form's rule has to be the server's rule.
 *
 * A form that accepts what the server refuses spends a round trip to say so, and
 * one that refuses what the server accepts locks a customer out of fixing their
 * own order — which is what the eight-digit-only rule did to anyone whose EA
 * backup codes are six digits long.
 */

// The suite declares its globals rather than relying on Vitest's, so React
// Testing Library's automatic cleanup is not installed. Without this the second
// render finds two of every field.
afterEach(cleanup);

const strings: CredentialStrings = {
    title: 'Update details',
    email_label: 'Email',
    password_label: 'Password',
    codes_label: 'Backup code',
    codes_note: 'Three codes, six or eight digits each.',
    submit: 'Save',
    cancel: 'Cancel',
    close: 'Close',
    fix_errors: 'Check the fields below.',
    invalid_email: 'That email does not look right.',
    password_required: 'The password is needed.',
    invalid_code: 'Six or eight digits.',
    duplicate_codes: 'The three codes must differ.',
};

function fill(values: {
    email?: string;
    password?: string;
    codes?: [string, string, string];
}) {
    if (values.email !== undefined) {
        fireEvent.change(screen.getByLabelText('Email'), {
            target: { value: values.email },
        });
    }

    if (values.password !== undefined) {
        fireEvent.change(screen.getByLabelText('Password'), {
            target: { value: values.password },
        });
    }

    (values.codes ?? []).forEach((code, index) => {
        fireEvent.change(screen.getByLabelText(`Backup code ${index + 1}`), {
            target: { value: code },
        });
    });
}

function mount(onSubmit = vi.fn()) {
    render(
        <CredentialForm
            onClose={() => undefined}
            onSubmit={onSubmit}
            strings={strings}
        />,
    );

    return onSubmit;
}

describe('the credential form', () => {
    it('accepts six-digit codes, which is what the tracker always accepted', () => {
        const onSubmit = mount();

        fill({
            email: 'player@example.com',
            password: 'hunter2',
            codes: ['123456', '234567', '345678'],
        });
        fireEvent.click(screen.getByRole('button', { name: 'Save' }));

        expect(onSubmit).toHaveBeenCalledWith({
            email: 'player@example.com',
            password: 'hunter2',
            codes: ['123456', '234567', '345678'],
        });
    });

    it('accepts eight-digit codes', () => {
        const onSubmit = mount();

        fill({
            email: 'player@example.com',
            password: 'hunter2',
            codes: ['12345678', '23456789', '34567890'],
        });
        fireEvent.click(screen.getByRole('button', { name: 'Save' }));

        expect(onSubmit).toHaveBeenCalledTimes(1);
    });

    it.each([['1234567'], ['12345'], ['abcdefgh'], ['']])(
        'refuses %s as a code',
        (code) => {
            const onSubmit = mount();

            fill({
                email: 'player@example.com',
                password: 'hunter2',
                codes: [code, '234567', '345678'],
            });
            fireEvent.click(screen.getByRole('button', { name: 'Save' }));

            expect(onSubmit).not.toHaveBeenCalled();
            expect(screen.getByText('Six or eight digits.')).toBeVisible();
        },
    );

    it('refuses three codes that are not three different codes', () => {
        const onSubmit = mount();

        fill({
            email: 'player@example.com',
            password: 'hunter2',
            codes: ['123456', '123456', '345678'],
        });
        fireEvent.click(screen.getByRole('button', { name: 'Save' }));

        expect(onSubmit).not.toHaveBeenCalled();
        expect(screen.getByText('The three codes must differ.')).toBeVisible();
    });

    it('refuses an address that is not one, and says so once above the fields', () => {
        const onSubmit = mount();

        fill({
            email: 'player@example',
            password: 'hunter2',
            codes: ['123456', '234567', '345678'],
        });
        fireEvent.click(screen.getByRole('button', { name: 'Save' }));

        expect(onSubmit).not.toHaveBeenCalled();
        expect(screen.getByRole('alert')).toHaveTextContent(
            'Check the fields below.',
        );
    });

    it('says nothing about any field until the customer has tried to save', () => {
        mount();

        expect(screen.queryByRole('alert')).not.toBeInTheDocument();
        expect(
            screen.queryByText('That email does not look right.'),
        ).not.toBeInTheDocument();
    });

    it('shows what the server said in place of its own summary', () => {
        render(
            <CredentialForm
                onClose={() => undefined}
                onSubmit={() => undefined}
                serverError="We could not reach the account just now."
                strings={strings}
            />,
        );

        expect(screen.getByRole('alert')).toHaveTextContent(
            'We could not reach the account just now.',
        );
    });

    it('cannot be submitted twice while the first attempt is in flight', () => {
        render(
            <CredentialForm
                busy
                onClose={() => undefined}
                onSubmit={() => undefined}
                strings={strings}
            />,
        );

        expect(screen.getByRole('button', { name: /Save/ })).toBeDisabled();
        expect(screen.getByRole('button', { name: 'Cancel' })).toBeDisabled();
    });
});
