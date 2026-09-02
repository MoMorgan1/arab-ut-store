import { cleanup, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';

import { ServiceReviewsSection } from '@/components/store/service-reviews-section';
import type { ServiceReviewsData } from '@/types/store-content';

afterEach(cleanup);

const mockServiceReviews: ServiceReviewsData = {
    service: 'rivals',
    title: 'ماذا يقول عملاء الرايفلز',
    hint: 'تقييمات موثّقة من طلبات الرايفلز اكتملت عبر المتجر',
    readAll: 'اقرأ كل تقييمات الرايفلز',
    readAllUrl: '/reviews?service=rivals',
    reviews: {
        average: 4.8,
        count: 5,
        verifiedCount: 4,
        distribution: [
            { rating: 5, count: 4, percent: 80 },
            { rating: 4, count: 1, percent: 20 },
            { rating: 3, count: 0, percent: 0 },
            { rating: 2, count: 0, percent: 0 },
            { rating: 1, count: 0, percent: 0 },
        ],
        items: [
            {
                id: 'rev-1',
                reviewerName: 'خالد',
                reviewerLocation: 'الرياض',
                rating: 5,
                body: 'خدمة رايفلز أسطورية وسريعة جداً',
                verified: true,
                hasComment: true,
                publishedAt: '2026-09-01T12:00:00Z',
            },
            {
                id: 'rev-2',
                reviewerName: 'سلطان',
                reviewerLocation: 'جدة',
                rating: 5,
                body: 'ممتازين كالعادة',
                verified: true,
                hasComment: true,
                publishedAt: '2026-09-01T10:00:00Z',
            },
            {
                id: 'rev-3',
                reviewerName: 'عبدالله',
                reviewerLocation: null,
                rating: 4,
                body: 'تجربة رائعة',
                verified: false,
                hasComment: true,
                publishedAt: '2026-08-30T10:00:00Z',
            },
        ],
    },
    translations: {
        eyebrow: 'تقييمات العملاء',
        service_eyebrow: 'تقييمات العملاء',
        service_title: 'ماذا يقول عملاء الرايفلز',
        service_hint: 'تقييمات موثّقة من طلبات الرايفلز اكتملت عبر المتجر',
        service_read_all: 'اقرأ كل تقييمات الرايفلز',
        service_all: 'كل الخدمات',
        title: 'التقييمات',
        empty: 'لا توجد تقييمات',
        view_all: 'عرض الكل',
        anonymous_customer: 'عميل عرب التيميت',
        verified: 'طلب موثّق',
        rating_label: ':rating من 5',
        summary: ':average من 5 بناءً على :count تقييم',
        of_count: 'من :count تقييم',
        verified_count: ':count طلب موثّق',
        distribution_label: 'توزيع النجوم',
    },
};

describe('ServiceReviewsSection', () => {
    it('returns null when serviceReviews is null', () => {
        const { container } = render(
            <ServiceReviewsSection
                direction="rtl"
                locale="ar"
                serviceReviews={null}
            />,
        );

        expect(container.firstChild).toBeNull();
    });

    it('returns null when reviews count is zero or items are empty', () => {
        const emptyReviews: ServiceReviewsData = {
            ...mockServiceReviews,
            reviews: {
                ...mockServiceReviews.reviews,
                count: 0,
                items: [],
            },
        };

        const { container } = render(
            <ServiceReviewsSection
                direction="rtl"
                locale="ar"
                serviceReviews={emptyReviews}
            />,
        );

        expect(container.firstChild).toBeNull();
    });

    it('renders the section with heading, summary, rail, and link', () => {
        render(
            <ServiceReviewsSection
                direction="rtl"
                locale="ar"
                serviceReviews={mockServiceReviews}
            />,
        );

        const section = screen.getByRole('region', {
            name: 'ماذا يقول عملاء الرايفلز',
        });
        expect(section).toBeInTheDocument();
        expect(section).toHaveClass('manual-section');

        expect(screen.getByText('تقييمات العملاء')).toBeInTheDocument();
        expect(
            screen.getByRole('heading', {
                level: 2,
                name: 'ماذا يقول عملاء الرايفلز',
            }),
        ).toHaveAttribute('id', 'service-reviews-heading');
        expect(
            screen.getByText(
                'تقييمات موثّقة من طلبات الرايفلز اكتملت عبر المتجر',
            ),
        ).toBeInTheDocument();

        expect(
            screen.getByText('خدمة رايفلز أسطورية وسريعة جداً'),
        ).toBeInTheDocument();
        expect(screen.getByText('ممتازين كالعادة')).toBeInTheDocument();
        expect(screen.getByText('تجربة رائعة')).toBeInTheDocument();

        const readAllLink = screen.getByRole('link', {
            name: 'اقرأ كل تقييمات الرايفلز',
        });
        expect(readAllLink).toHaveAttribute('href', '/reviews?service=rivals');
        expect(readAllLink).toHaveClass('store-reviews__more');
    });
});
