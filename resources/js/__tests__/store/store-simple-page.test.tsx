import { cleanup, render, screen } from '@testing-library/react';
import { afterEach, expect, it, vi } from 'vitest';

import SimpleStorePage from '@/pages/store/simple-page';

const mockPage = vi.hoisted(() => ({
    props: {
        direction: 'ltr',
        displayCurrency: 'SAR',
        displayCurrencies: ['SAR', 'USD'],
        locale: 'en',
        page: {
            body: 'We are preparing the SBC catalog and automated product connection.',
            key: 'sbc',
            title: 'SBC Services',
        },
        storeShell: {
            homeUrl: '/en',
        },
        ui: {
            brand: 'Arab UT',
            currency_selector: 'Choose display currency',
            language: 'العربية',
            simple_pages: {
                back_home: 'Back to home',
                eyebrow: 'Arab UT',
            },
            skip_to_content: 'Skip to content',
            store_tools: 'Store tools',
        },
    },
    url: '/en/sbc',
}));

vi.mock('@inertiajs/react', () => ({
    Head: ({ title }: { title: string }) => <title>{title}</title>,
    usePage: () => mockPage,
}));

afterEach(cleanup);

it('renders a non-transactional branded destination', () => {
    render(<SimpleStorePage />);

    expect(screen.getByRole('heading', { name: 'SBC Services' })).toBeVisible();
    expect(
        screen.getByText(
            'We are preparing the SBC catalog and automated product connection.',
        ),
    ).toBeVisible();
    expect(screen.getByRole('banner')).toBeVisible();
    expect(screen.queryByRole('form')).not.toBeInTheDocument();
    expect(
        screen.queryByRole('button', { name: /pay|checkout/i }),
    ).not.toBeInTheDocument();
    expect(document.querySelector('a[href="#"]')).not.toBeInTheDocument();
});
