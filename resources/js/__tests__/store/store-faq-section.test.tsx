import { cleanup, render, screen } from '@testing-library/react';
import { afterEach, expect, it } from 'vitest';

import { FaqSection } from '@/components/store/faq-section';

afterEach(cleanup);

it('renders the unchanged four FAQs as native disclosures', () => {
    const { container } = render(
        <FaqSection
            entries={[
                {
                    answer: 'متواجدين بخدمتكم ٢٤ ساعة على مدار الأسبوع',
                    id: 'faq-1',
                    question: 'ما أوقات عمل المتجر؟',
                },
                {
                    answer: 'على حسب الكمية والضغط.',
                    id: 'faq-2',
                    question: 'كم يستغرق وقت طلبي؟',
                },
                {
                    answer: 'طريقة نقل آلية آمنة.',
                    id: 'faq-3',
                    question: 'نسبة الأمان على حسابك؟',
                },
                {
                    answer: 'عن طريق تطبيق Companion.',
                    id: 'faq-4',
                    question: 'كيف يتم تسليم الكوينز بعد الشراء؟',
                },
            ]}
            translations={{
                eyebrow: 'الأسئلة الشائعة',
                title: 'كل ما تحتاج معرفته',
            }}
        />,
    );

    expect(container.querySelectorAll('details')).toHaveLength(4);
    expect(container.querySelectorAll('summary')).toHaveLength(4);
    expect(
        container.querySelectorAll(
            'summary svg.store-faq__chevron[aria-hidden="true"]',
        ),
    ).toHaveLength(4);
    expect(
        screen.getByText('ما أوقات عمل المتجر؟').closest('details'),
    ).toBeTruthy();
});
