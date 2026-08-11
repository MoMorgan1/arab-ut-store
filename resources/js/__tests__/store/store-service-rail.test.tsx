import {
    act,
    cleanup,
    fireEvent,
    render,
    screen,
} from '@testing-library/react';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';

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

beforeEach(() => {
    Object.defineProperties(HTMLUListElement.prototype, {
        clientWidth: { configurable: true, get: () => 320 },
        scrollWidth: { configurable: true, get: () => 1280 },
    });
    Object.defineProperty(HTMLElement.prototype, 'scrollBy', {
        configurable: true,
        value: vi.fn(),
    });
    Object.defineProperty(HTMLElement.prototype, 'scrollTo', {
        configurable: true,
        value: vi.fn(),
    });
    vi.stubGlobal('matchMedia', () => ({ matches: false }));
});

it('loops back to the first card after reaching the rail end', () => {
    vi.useFakeTimers();
    const { container } = render(
        <ServiceRail
            direction="ltr"
            services={services}
            translations={{
                eyebrow: 'More services',
                title: 'Choose a service',
            }}
        />,
    );
    const track = container.querySelector<HTMLElement>(
        '.store-services-rail__track',
    )!;

    Object.defineProperty(track, 'scrollLeft', {
        configurable: true,
        value: 960,
    });
    act(() => vi.advanceTimersByTime(3_000));

    expect(track.scrollTo).toHaveBeenCalledWith({
        behavior: 'auto',
        left: 0,
    });
});

afterEach(() => {
    cleanup();
    vi.useRealTimers();
    vi.restoreAllMocks();
    vi.unstubAllGlobals();
});

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

it('moves automatically and pauses while a service link has focus', () => {
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
    const firstService = screen.getByRole('link', { name: /SBC/ });

    act(() => vi.advanceTimersByTime(3_000));

    expect(track?.scrollBy).toHaveBeenCalled();
    expect(track).toHaveAttribute('dir', 'rtl');

    const callCount = vi.mocked(track!.scrollBy).mock.calls.length;
    fireEvent.focus(firstService);
    act(() => vi.advanceTimersByTime(6_000));
    expect(track?.scrollBy).toHaveBeenCalledTimes(callCount);

    fireEvent.blur(firstService);
    act(() => vi.advanceTimersByTime(3_000));
    expect(vi.mocked(track!.scrollBy).mock.calls.length).toBeGreaterThan(
        callCount,
    );
});
