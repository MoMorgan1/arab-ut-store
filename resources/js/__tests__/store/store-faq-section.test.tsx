import { cleanup, render, screen } from '@testing-library/react';
import { afterEach, expect, it } from 'vitest';

import { FaqSection } from '@/components/store/faq-section';

afterEach(cleanup);

it('renders the unchanged four FAQs as native disclosures', () => {
    const { container } = render(
        <FaqSection
            entries={[
                {
                    question: 'Ù…Ø§ Ø£ÙˆÙ‚Ø§Øª Ø¹Ù…Ù„ Ø§Ù„Ù…ØªØ¬Ø±ØŸ',
                    answer: 'Ù…ØªÙˆØ§Ø¬Ø¯ÙŠÙ† Ø¨Ø®Ø¯Ù…ØªÙƒÙ… Ù¢Ù¤ Ø³Ø§Ø¹Ø© Ø¹Ù„Ù‰ Ù…Ø¯Ø§Ø± Ø§Ù„Ø£Ø³Ø¨ÙˆØ¹',
                },
                {
                    question: 'ÙƒÙ… ÙŠØ³ØªØºØ±Ù‚ ÙˆÙ‚Øª Ø·Ù„Ø¨ÙŠØŸ',
                    answer: 'Ø¹Ù„Ù‰ Ø­Ø³Ø¨ Ø§Ù„ÙƒÙ…ÙŠØ© ÙˆØ§Ù„Ø¶ØºØ·.',
                },
                {
                    question: 'Ù†Ø³Ø¨Ø© Ø§Ù„Ø£Ù…Ø§Ù† Ø¹Ù„Ù‰ Ø­Ø³Ø§Ø¨ÙƒØŸ',
                    answer: 'Ø·Ø±ÙŠÙ‚Ø© Ù†Ù‚Ù„ Ø¢Ù„ÙŠØ© Ø¢Ù…Ù†Ø©.',
                },
                {
                    question:
                        'ÙƒÙŠÙ ÙŠØªÙ… ØªØ³Ù„ÙŠÙ… Ø§Ù„ÙƒÙˆÙŠÙ†Ø² Ø¨Ø¹Ø¯ Ø§Ù„Ø´Ø±Ø§Ø¡ØŸ',
                    answer: 'Ø¹Ù† Ø·Ø±ÙŠÙ‚ ØªØ·Ø¨ÙŠÙ‚ Companion.',
                },
            ]}
            translations={{
                eyebrow: 'Ø§Ù„Ø£Ø³Ø¦Ù„Ø© Ø§Ù„Ø´Ø§Ø¦Ø¹Ø©',
                title: 'ÙƒÙ„ Ù…Ø§ ØªØ­ØªØ§Ø¬ Ù…Ø¹Ø±ÙØªÙ‡',
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
        screen
            .getByText('Ù…Ø§ Ø£ÙˆÙ‚Ø§Øª Ø¹Ù…Ù„ Ø§Ù„Ù…ØªØ¬Ø±ØŸ')
            .closest('details'),
    ).toBeTruthy();
});
