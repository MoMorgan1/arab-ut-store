import { cleanup, render, screen } from '@testing-library/react';
import { afterEach, expect, it, vi } from 'vitest';

import { ServiceRail } from '@/components/store/service-rail';
import type { HomeServiceCard } from '@/types/store-content';

const services: HomeServiceCard[] = [
    ['sbc', 'SBC', '/sbc'],
    ['objectives', 'Objectives', '/objectives'],
    ['fut_champions', 'FUT Champions', '/fut-champions'],
    ['rivals', 'Rivals', '/rivals'],
    ['sell_coins', 'Sell Coins', 'https://sell.arab-ut.com/'],
].map(([key, title, href]) => ({
    description: `${title} description`,
    external: key === 'sell_coins',
    href,
    imageUrl: `/images/${key}.webp`,
    key: key as HomeServiceCard['key'],
    title,
}));

afterEach(cleanup);

it('renders five equal service links in the approved order', () => {
    render(
        <ServiceRail
            direction="ltr"
            services={services}
            translations={{
                eyebrow: 'More services',
                title: 'Choose a service',
            }}
        />,
    );

    expect(screen.getAllByTestId('service-card')).toHaveLength(5);
    expect(
        screen.getAllByTestId('service-card').map((card) => card.textContent),
    ).toEqual(
        expect.arrayContaining([
            'SBCSBC description',
            'Sell CoinsSell Coins description',
        ]),
    );
    expect(screen.getByRole('link', { name: /Sell Coins/ })).toHaveAttribute(
        'href',
        'https://sell.arab-ut.com/',
    );
    expect(screen.getByRole('link', { name: /Sell Coins/ })).toHaveAttribute(
        'target',
        '_blank',
    );
    expect(screen.getByRole('link', { name: /Sell Coins/ })).toHaveAttribute(
        'rel',
        'noreferrer noopener',
    );
});

it('uses native horizontal scrolling without autoplay', () => {
    vi.useFakeTimers();
    const { container } = render(
        <ServiceRail
            direction="rtl"
            services={services}
            translations={{ eyebrow: 'خدمات أخرى', title: 'اختر خدمتك' }}
        />,
    );
    const track = container.querySelector<HTMLElement>(
        '.store-services-rail__track',
    );

    vi.advanceTimersByTime(30_000);
    expect(track?.scrollLeft).toBe(0);
    expect(track).toHaveAttribute('dir', 'rtl');
    expect(screen.queryByRole('button')).not.toBeInTheDocument();
    vi.useRealTimers();
});
