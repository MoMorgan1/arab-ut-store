import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import OrderReviewCard from '@/components/account/order-review-card';
import type { AccountTranslations } from '@/types/account';

const form = vi.hoisted(() => ({
    data: { body: '', rating: 0 } as { body: string; rating: number },
    errors: {} as Record<string, string>,
    processing: false,
    post: vi.fn(),
    setData: vi.fn((key: 'body' | 'rating', value: string | number) => {
        (form.data as Record<string, string | number>)[key] = value;
    }),
    clearErrors: vi.fn(),
}));

vi.mock('@inertiajs/react', () => ({
    useForm: () => form,
}));

const translations: AccountTranslations['orders']['review'] = {
    title: 'Rate your order',
    helper: 'Your review appears in the store under your first name.',
    rating_label: 'Your rating',
    rating_value: ':rating of 5',
    star_label: ':rating of 5',
    comment_label: 'Your comment',
    comment_placeholder: 'Write a comment (optional)',
    counter: ':count / :max',
    submit: 'Send review',
    submitting: 'Sending…',
    submitted_title: 'Your review',
    verified_badge: 'Verified order',
    thanks_visible: 'Thanks :name, your review is live in the store.',
    thanks_hidden: 'Thanks :name, we received your review and will look at it.',
    submitted_toast: 'Thanks for your review.',
    not_eligible: 'This order cannot be reviewed.',
    already_reviewed: 'You already reviewed this order.',
};

beforeEach(() => {
    form.data = { body: '', rating: 0 };
    form.processing = false;
    form.post.mockReset();
    form.setData.mockClear();
});

afterEach(cleanup);

describe('OrderReviewCard', () => {
    it('renders five star radios and keeps submit disabled until one is chosen', () => {
        render(
            <OrderReviewCard
                customerName="Mohamed"
                locale="en"
                review={{
                    url: '/my-account/orders/abc/review',
                    submitted: null,
                }}
                translations={translations}
            />,
        );

        const stars = screen.getAllByRole('radio');
        expect(stars).toHaveLength(5);
        expect(
            screen.getByRole('button', { name: 'Send review' }),
        ).toBeDisabled();

        fireEvent.click(stars[3]);
        expect(form.setData).toHaveBeenCalledWith('rating', 4);
    });

    it('moves between stars with the arrow keys and selects with Enter', () => {
        render(
            <OrderReviewCard
                customerName="Mohamed"
                locale="en"
                review={{
                    url: '/my-account/orders/abc/review',
                    submitted: null,
                }}
                translations={translations}
            />,
        );

        const stars = screen.getAllByRole('radio');
        stars[0].focus();
        fireEvent.keyDown(stars[0], { key: 'ArrowRight' });
        expect(document.activeElement).toBe(stars[1]);

        fireEvent.keyDown(stars[1], { key: 'Enter' });
        expect(form.setData).toHaveBeenCalledWith('rating', 2);
    });

    it('caps the comment at 600 characters and shows the counter', () => {
        render(
            <OrderReviewCard
                customerName="Mohamed"
                locale="en"
                review={{
                    url: '/my-account/orders/abc/review',
                    submitted: null,
                }}
                translations={translations}
            />,
        );

        const comment = screen.getByPlaceholderText(
            'Write a comment (optional)',
        );
        expect(comment).toHaveAttribute('maxlength', '600');
        expect(screen.getByText('0 / 600')).toBeInTheDocument();
    });

    it('posts to the review url once a star is chosen', () => {
        form.data = { body: 'Great', rating: 5 };

        render(
            <OrderReviewCard
                customerName="Mohamed"
                locale="en"
                review={{
                    url: '/my-account/orders/abc/review',
                    submitted: null,
                }}
                translations={translations}
            />,
        );

        const submit = screen.getByRole('button', { name: 'Send review' });
        expect(submit).toBeEnabled();
        fireEvent.click(submit);
        expect(form.post).toHaveBeenCalledWith(
            '/my-account/orders/abc/review',
            expect.anything(),
        );
    });

    it('shows the submitted review read-only with the verified badge', () => {
        render(
            <OrderReviewCard
                customerName="Mohamed"
                locale="en"
                review={{
                    url: '/my-account/orders/abc/review',
                    submitted: {
                        rating: 5,
                        body: 'Fast and safe.',
                        publishedAt: '2026-09-02T10:00:00Z',
                        visible: true,
                    },
                }}
                translations={translations}
            />,
        );

        expect(screen.queryAllByRole('radio')).toHaveLength(0);
        expect(screen.getByText('Fast and safe.')).toBeInTheDocument();
        expect(screen.getByText('Verified order')).toBeInTheDocument();
        expect(
            screen.getByText(
                'Thanks Mohamed, your review is live in the store.',
            ),
        ).toBeInTheDocument();
    });

    it('tells the customer a low rating is under review', () => {
        render(
            <OrderReviewCard
                customerName="Mohamed"
                locale="ar"
                review={{
                    url: '/my-account/orders/abc/review',
                    submitted: {
                        rating: 2,
                        body: null,
                        publishedAt: null,
                        visible: false,
                    },
                }}
                translations={translations}
            />,
        );

        expect(
            screen.getByText(
                'Thanks Mohamed, we received your review and will look at it.',
            ),
        ).toBeInTheDocument();
    });
});
