import {
    act,
    cleanup,
    fireEvent,
    render,
    screen,
} from '@testing-library/react';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';

import { ReviewsSection } from '@/components/store/reviews-section';

beforeEach(() => {
    Object.defineProperties(HTMLUListElement.prototype, {
        clientWidth: { configurable: true, get: () => 320 },
        scrollWidth: { configurable: true, get: () => 1280 },
    });
    Object.defineProperty(HTMLElement.prototype, 'scrollBy', {
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

afterEach(() => {
    cleanup();
    vi.useRealTimers();
    vi.restoreAllMocks();
    vi.unstubAllGlobals();
});

it('renders premium public 4–5 star cards with customer names and locations', () => {
    vi.useFakeTimers();
    const { container } = render(
        <ReviewsSection
            locale="en"
            reviews={{
                average: 4.5,
                count: 2,
                items: [
                    {
                        id: 'one',
                        reviewerName: 'Customer',
                        reviewerLocation: 'Riyadh',
                        rating: 5,
                        body: 'Excellent.',
                        verified: true,
                        publishedAt: '2026-08-10T12:00:00+00:00',
                    },
                    {
                        id: 'two',
                        reviewerName: 'Another customer',
                        reviewerLocation: 'Cairo',
                        rating: 4,
                        body: 'Fast service.',
                        verified: false,
                        publishedAt: '2026-08-09T12:00:00+00:00',
                    },
                ],
            }}
            reviewsUrl="/en/reviews"
            translations={translations()}
        />,
    );

    const cards = screen.getAllByTestId('review-card');

    expect(cards).toHaveLength(2);
    expect(cards[0]).toHaveClass('store-review-card');
    expect(cards[0]).toHaveClass('store-review-card--gold');
    expect(cards[1]).toHaveClass('store-review-card');
    expect(cards[1]).not.toHaveClass('store-review-card--gold');
    expect(screen.getByLabelText('5 out of 5')).toBeInTheDocument();
    expect(screen.getByLabelText('4 out of 5')).toBeInTheDocument();
    expect(screen.getByText('Riyadh')).toBeInTheDocument();
    expect(screen.getByText('Cairo')).toBeInTheDocument();
    expect(screen.getAllByText('Verified order')).toHaveLength(1);
    expect(container.textContent).not.toMatch(/[\w.+-]+@[\w.-]+|\+?\d{9,}/);
    const track = container.querySelector<HTMLElement>('.store-reviews-rail')!;

    act(() => vi.advanceTimersByTime(160));
    expect(vi.mocked(track.scrollBy).mock.calls.length).toBeGreaterThan(0);

    const callsBeforeDrag = vi.mocked(track.scrollBy).mock.calls.length;
    fireEvent.touchStart(track);
    fireEvent.scroll(track);
    fireEvent.touchEnd(track);
    act(() => vi.advanceTimersByTime(120));
    expect(track.scrollBy).toHaveBeenCalledTimes(callsBeforeDrag);

    act(() => vi.advanceTimersByTime(650));
    act(() => vi.advanceTimersByTime(100));
    expect(track.scrollBy).toHaveBeenCalledTimes(callsBeforeDrag);

    act(() => vi.advanceTimersByTime(250));
    act(() => vi.advanceTimersByTime(100));
    expect(vi.mocked(track.scrollBy).mock.calls.length).toBeGreaterThan(
        callsBeforeDrag,
    );
});

function translations() {
    return {
        eyebrow: 'Reviews',
        title: 'Customer stories',
        empty: 'No reviews',
        view_all: 'View all',
        verified: 'Verified order',
        rating_label: ':rating out of 5',
        summary: ':average from :count reviews',
        anonymous_customer: 'Customer',
    };
}
