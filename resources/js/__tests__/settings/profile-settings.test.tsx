import { render, screen } from '@testing-library/react';
import type { ReactNode } from 'react';
import { describe, expect, it, vi } from 'vitest';

vi.mock('@inertiajs/react', () => ({
    Form: ({ children }: { children: (state: object) => ReactNode }) => (
        <form>{children({ errors: {}, processing: false })}</form>
    ),
    Head: () => null,
    usePage: () => ({
        props: {
            auth: {
                user: {
                    created_at: '2026-08-15T00:00:00Z',
                    email: 'customer@example.com',
                    email_verified_at: '2026-08-15T00:00:00Z',
                    first_name: 'Customer',
                    id: 1,
                    last_name: 'Account',
                    name: 'Customer Account',
                    updated_at: '2026-08-15T00:00:00Z',
                },
            },
        },
    }),
}));

import Profile from '@/pages/settings/profile';

describe('legacy profile settings', () => {
    it('does not offer account deletion before retention rules are approved', () => {
        render(<Profile />);

        expect(
            screen.queryByRole('button', { name: /delete account/i }),
        ).not.toBeInTheDocument();
    });
});
