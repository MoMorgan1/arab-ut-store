import {
    act,
    cleanup,
    fireEvent,
    render,
    screen,
} from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import {
    ReviewsSection,
    formatPublished,
} from '@/components/store/reviews-section';
import type { ReviewTranslations } from '@/types/store-content';

beforeEach(() => {
    Object.defineProperties(HTMLUListElement.prototype, {
        clientWidth: { configurable: true, get: () => 320 },
        scrollWidth: { configurable: true, get: () => 1280 },
    });
    Object.defineProperty(HTMLElement.prototype, 'scrollBy', {
        configurable: true,
        value: vi.fn(),
    });
    Object.defineProperty(HTMLElement.prototype, 'scrollIntoView', {
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

function reviews() {
    return {
        average: 4.5,
        count: 2,
        verifiedCount: 1,
        distribution: [
            { rating: 5, count: 1, percent: 50 },
            { rating: 4, count: 1, percent: 50 },
            { rating: 3, count: 0, percent: 0 },
            { rating: 2, count: 0, percent: 0 },
            { rating: 1, count: 0, percent: 0 },
        ],
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
    };
}

function translations(): ReviewTranslations {
    return {
        eyebrow: 'Reviews',
        title: 'Customer stories',
        intro: 'Verified reviews from real orders.',
        empty: 'No reviews',
        view_all: 'View all',
        read_all: 'Read all reviews',
        rate_your_order: 'Rate your order',
        verified: 'Verified order',
        rating_label: ':rating out of 5',
        summary: ':average from :count reviews',
        of_count: 'from :count reviews',
        verified_count: ':count verified orders',
        distribution_label: 'Star distribution',
        rail_label: 'Latest reviews',
        previous_cards: 'Previous reviews',
        next_cards: 'Next reviews',
        anonymous_customer: 'Customer',
    };
}

describe('ReviewsSection', () => {
    it('renders the trust summary, the cards, and both calls to action', () => {
        vi.useFakeTimers();
        const { container } = render(
            <ReviewsSection
                locale="en"
                rateUrl="/en/my-account/orders"
                reviews={reviews()}
                reviewsUrl="/en/reviews"
                translations={translations()}
            />,
        );

        const summary = screen.getByRole('group', {
            name: '4.5 from 2 reviews',
        });
        expect(summary).toHaveTextContent('4.5');
        expect(summary).toHaveTextContent('from 2 reviews');
        expect(summary).toHaveTextContent('1 verified orders');
        expect(
            screen.getByRole('list', { name: 'Star distribution' }),
        ).toHaveTextContent('50%');

        const cards = screen.getAllByTestId('review-card');
        expect(cards).toHaveLength(2);
        expect(cards[0]).toHaveClass('store-review-card--gold');
        expect(cards[1]).not.toHaveClass('store-review-card--gold');
        expect(
            screen.getByRole('img', { name: '5 out of 5' }),
        ).toBeInTheDocument();
        expect(screen.getByText('Riyadh')).toBeInTheDocument();
        expect(screen.getAllByText('Verified order')).toHaveLength(1);
        expect(container.textContent).not.toMatch(/[\w.+-]+@[\w.-]+|\+?\d{9,}/);

        expect(
            screen.getByRole('link', { name: 'Read all reviews' }),
        ).toHaveAttribute('href', '/en/reviews');
        expect(
            screen.getByRole('link', { name: 'Rate your order' }),
        ).toHaveAttribute('href', '/en/my-account/orders');
    });

    it('keeps the gentle auto-scroll and pauses it while the visitor drags', () => {
        vi.useFakeTimers();
        const { container } = render(
            <ReviewsSection
                locale="en"
                reviews={reviews()}
                reviewsUrl="/en/reviews"
                translations={translations()}
            />,
        );
        const track = container.querySelector<HTMLElement>(
            '.store-reviews-rail',
        )!;

        act(() => vi.advanceTimersByTime(160));
        expect(vi.mocked(track.scrollBy).mock.calls.length).toBeGreaterThan(0);

        const callsBeforeDrag = vi.mocked(track.scrollBy).mock.calls.length;
        fireEvent.touchStart(track);
        fireEvent.scroll(track);
        fireEvent.touchEnd(track);
        act(() => vi.advanceTimersByTime(120));
        expect(track.scrollBy).toHaveBeenCalledTimes(callsBeforeDrag);
    });

    it('scrolls one card per arrow press', () => {
        vi.useFakeTimers();
        const { container } = render(
            <ReviewsSection
                locale="en"
                reviews={reviews()}
                reviewsUrl="/en/reviews"
                translations={translations()}
            />,
        );
        const track = container.querySelector<HTMLElement>(
            '.store-reviews-rail',
        )!;
        const before = vi.mocked(track.scrollBy).mock.calls.length;

        fireEvent.click(screen.getByRole('button', { name: 'Next reviews' }));

        const calls = vi.mocked(track.scrollBy).mock.calls;
        expect(calls.length).toBe(before + 1);
        expect(calls.at(-1)?.[0]).toEqual(
            expect.objectContaining({ behavior: 'smooth' }),
        );
    });

    it('shows the empty state without a summary when there are no reviews', () => {
        render(
            <ReviewsSection
                locale="ar"
                reviews={{ average: null, count: 0, items: [] }}
                reviewsUrl="/reviews"
                translations={translations()}
            />,
        );

        expect(screen.getByText('No reviews')).toBeInTheDocument();
        expect(screen.queryByRole('group')).toBeNull();
    });
});

describe('formatPublished', () => {
    const now = new Date('2026-09-02T12:00:00Z');

    it('is relative under a year and absolute beyond it', () => {
        expect(formatPublished('2026-08-30T12:00:00Z', 'en', now)).toBe(
            '3 days ago',
        );
        expect(formatPublished('2026-07-02T12:00:00Z', 'en', now)).toBe(
            '2 months ago',
        );
        expect(formatPublished('2026-09-02T09:00:00Z', 'en', now)).toBe(
            'today',
        );
        expect(formatPublished('2024-01-01T12:00:00Z', 'en', now)).toMatch(
            /2024/,
        );
    });

    it('speaks Arabic for the Arabic store', () => {
        expect(formatPublished('2026-08-30T12:00:00Z', 'ar', now)).toMatch(
            /قبل/,
        );
    });
});
