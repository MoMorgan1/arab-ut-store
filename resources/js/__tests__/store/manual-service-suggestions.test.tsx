import { cleanup, render, screen } from '@testing-library/react';
import { afterEach, expect, it } from 'vitest';

import { ManualServiceSuggestions } from '@/components/configurator/manual-services/manual-service-suggestions';

afterEach(cleanup);

it('renders only the server-selected services as fully linked recommendations', () => {
    render(
        <ManualServiceSuggestions
            services={[
                {
                    key: 'sbc',
                    title: 'SBC services',
                    description: 'Complete challenges for your club.',
                    href: '/en/sbc',
                    imageUrl: '/images/store/services/sbc.webp',
                },
                {
                    key: 'rivals',
                    title: 'Division Rivals',
                    description: 'Move up to your target division.',
                    href: '/en/rivals',
                    imageUrl: '/images/store/services/rivals.webp',
                },
            ]}
            translations={{
                eyebrow: 'More services',
                title: 'Continue with Arab UT',
                open: 'Open service',
            }}
        />,
    );

    expect(
        screen.getByRole('heading', { name: 'Continue with Arab UT' }),
    ).toBeVisible();
    expect(screen.getByRole('link', { name: /SBC services/ })).toHaveAttribute(
        'href',
        '/en/sbc',
    );
    expect(
        screen.getByRole('link', { name: /Division Rivals/ }),
    ).toHaveAttribute('href', '/en/rivals');
});
