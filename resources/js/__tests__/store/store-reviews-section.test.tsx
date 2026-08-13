import { cleanup, render, screen } from '@testing-library/react';
import { afterEach, expect, it, vi } from 'vitest';

import { ReviewsSection } from '@/components/store/reviews-section';

afterEach(cleanup);

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

    expect(screen.getAllByTestId('review-card')).toHaveLength(2);
    expect(screen.getByLabelText('5 out of 5')).toBeInTheDocument();
    expect(screen.getByLabelText('4 out of 5')).toBeInTheDocument();
    expect(screen.getByText('Riyadh')).toBeInTheDocument();
    expect(screen.getByText('Cairo')).toBeInTheDocument();
    expect(screen.getAllByText('Verified order')).toHaveLength(1);
    expect(container.textContent).not.toMatch(/[\w.+-]+@[\w.-]+|\+?\d{9,}/);
    vi.advanceTimersByTime(30_000);
    expect(container.querySelector('.store-reviews-rail')?.scrollLeft).toBe(0);
    vi.useRealTimers();
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
