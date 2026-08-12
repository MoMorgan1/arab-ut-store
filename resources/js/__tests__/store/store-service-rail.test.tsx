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
    vi.stubGlobal('requestAnimationFrame', (callback: FrameRequestCallback) =>
        window.setTimeout(() => callback(performance.now()), 16),
    );
    vi.stubGlobal('cancelAnimationFrame', (handle: number) =>
        window.clearTimeout(handle),
    );
});

it('reverses smoothly instead of restarting after reaching the rail end', () => {
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
    act(() => vi.advanceTimersByTime(100));

    expect(track.scrollTo).not.toHaveBeenCalled();
    const reverseScroll = vi.mocked(track.scrollBy).mock.calls.at(-1)?.[0] as
        ScrollToOptions | undefined;
    expect(reverseScroll?.left).toBeLessThan(0);
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

it('moves continuously and pauses while a service link has focus', () => {
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

    act(() => vi.advanceTimersByTime(100));

    expect(vi.mocked(track!.scrollBy).mock.calls.length).toBeGreaterThan(0);
    const distance = Math.abs(
        vi
            .mocked(track!.scrollBy)
            .mock.calls.reduce(
                (total, [options]) =>
                    total + Number((options as ScrollToOptions).left ?? 0),
                0,
            ),
    );

    expect(distance).toBeGreaterThanOrEqual(4);
    expect(distance).toBeLessThanOrEqual(5);
    expect(track).toHaveAttribute('dir', 'rtl');

    const callCount = vi.mocked(track!.scrollBy).mock.calls.length;
    fireEvent.focus(firstService);
    act(() => vi.advanceTimersByTime(100));
    expect(track?.scrollBy).toHaveBeenCalledTimes(callCount);

    fireEvent.blur(firstService);
    act(() => vi.advanceTimersByTime(100));
    expect(vi.mocked(track!.scrollBy).mock.calls.length).toBeGreaterThan(
        callCount,
    );
});

it('waits for mobile scrolling to settle before resuming automatic movement', () => {
    vi.useFakeTimers();
    const { container } = render(
        <ServiceRail
            direction="rtl"
            services={services}
            translations={{
                eyebrow: 'More services',
                title: 'Choose a service',
            }}
        />,
    );
    const rail = container.querySelector<HTMLElement>('.store-services-rail')!;
    const track = container.querySelector<HTMLElement>(
        '.store-services-rail__track',
    )!;

    act(() => vi.advanceTimersByTime(100));
    const callsBeforeDrag = vi.mocked(track.scrollBy).mock.calls.length;

    fireEvent.touchStart(rail);
    fireEvent.scroll(track);
    fireEvent.touchEnd(rail);
    act(() => vi.advanceTimersByTime(120));

    expect(track.scrollBy).toHaveBeenCalledTimes(callsBeforeDrag);

    fireEvent.scroll(track);
    act(() => vi.advanceTimersByTime(120));
    expect(track.scrollBy).toHaveBeenCalledTimes(callsBeforeDrag);

    act(() => vi.advanceTimersByTime(200));
    act(() => vi.advanceTimersByTime(100));
    expect(vi.mocked(track.scrollBy).mock.calls.length).toBeGreaterThan(
        callsBeforeDrag,
    );
});
