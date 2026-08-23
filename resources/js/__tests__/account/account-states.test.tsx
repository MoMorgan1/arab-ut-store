import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, expect, it, vi } from 'vitest';

import AccountSectionError from '@/components/account/account-section-error';

afterEach(cleanup);

it('offers an accessible retry action for reusable section failures', () => {
    const retry = vi.fn();
    render(
        <AccountSectionError
            actionLabel="Try again"
            description="The section could not load."
            onRetry={retry}
            title="Could not load"
        />,
    );

    fireEvent.click(screen.getByRole('button', { name: 'Try again' }));
    expect(retry).toHaveBeenCalledOnce();
});
