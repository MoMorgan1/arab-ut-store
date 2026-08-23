import { cleanup, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import { StreamedText } from '@/components/chat/streamed-text';

describe('Formatted Assistant Chat Reply Rendering', () => {
    afterEach(() => {
        cleanup();
    });

    it('renders single paragraph without excessive wrappers', () => {
        const { container } = render(
            <StreamedText
                content="أهلًا بك، كيف أساعدك اليوم؟"
                isStreaming={false}
            />,
        );

        expect(
            screen.getByText('أهلًا بك، كيف أساعدك اليوم؟'),
        ).toBeInTheDocument();
        expect(container.querySelector('p')).toHaveClass(
            'leading-relaxed',
            'break-words',
        );
    });

    it('renders multiple paragraphs separated by comfortable spacing', () => {
        const content = 'الفقرة الأولى هنا.\n\nالفقرة الثانية هنا.';
        const { container } = render(
            <StreamedText content={content} isStreaming={false} />,
        );

        const paragraphs = container.querySelectorAll('p');
        expect(paragraphs).toHaveLength(2);
        expect(paragraphs[0].textContent).toBe('الفقرة الأولى هنا.');
        expect(paragraphs[1].textContent).toBe('الفقرة الثانية هنا.');
        expect(container.firstElementChild).toHaveClass('space-y-2.5');
    });

    it('renders unordered list items as real <ul> with <li> and RTL-aware indentation', () => {
        const content =
            'الخدمات المتاحة:\n- شحن كوينز\n- تحديات SBC\n- دوري الأبطال';
        const { container } = render(
            <StreamedText content={content} isStreaming={false} />,
        );

        const ul = container.querySelector('ul');
        expect(ul).not.toBeNull();
        expect(ul).toHaveClass('list-disc', 'ps-5', 'space-y-1');

        const items = container.querySelectorAll('li');
        expect(items).toHaveLength(3);
        expect(items[0].textContent).toBe('شحن كوينز');
        expect(items[1].textContent).toBe('تحديات SBC');
        expect(items[2].textContent).toBe('دوري الأبطال');
    });

    it('renders ordered list items as real <ol> with <li> and correct start', () => {
        const content = '1. اختر الخدمة\n2. أدخل بياناتك\n3. ادفع واستلم';
        const { container } = render(
            <StreamedText content={content} isStreaming={false} />,
        );

        const ol = container.querySelector('ol');
        expect(ol).not.toBeNull();
        expect(ol).toHaveClass('list-decimal', 'ps-5', 'space-y-1');
        expect(ol).toHaveAttribute('start', '1');

        const items = container.querySelectorAll('li');
        expect(items).toHaveLength(3);
        expect(items[0].textContent).toBe('اختر الخدمة');
        expect(items[1].textContent).toBe('أدخل بياناتك');
        expect(items[2].textContent).toBe('ادفع واستلم');
    });

    it('renders bold tokens as <strong> elements', () => {
        const content = 'هذا العرض **خاص جدا** اليوم';
        const { container } = render(
            <StreamedText content={content} isStreaming={false} />,
        );

        const strong = container.querySelector('strong');
        expect(strong).not.toBeNull();
        expect(strong?.textContent).toBe('خاص جدا');
        expect(strong).toHaveClass('font-bold');
    });

    it('renders money tokens in brand gold with dir="ltr" and data-testid="chat-money"', () => {
        const content = 'السعر الحالي: 3.70 SAR للتوصيل العادي';
        render(<StreamedText content={content} isStreaming={false} />);

        const moneyEl = screen.getByTestId('chat-money');
        expect(moneyEl).toBeInTheDocument();
        expect(moneyEl).toHaveTextContent('3.70 SAR');
        expect(moneyEl).toHaveAttribute('dir', 'ltr');
        expect(moneyEl).toHaveClass(
            'font-semibold',
            'text-[var(--chat-accent-ink)]',
        );
    });

    it('renders money in both orders and both numeral scripts without flipping order', () => {
        const content =
            'الأسعار: 3.70 SAR و SAR 6.20 و ٣.٧٠ SAR و SAR ١٠٠,٠٠٠ في متجرنا';
        render(<StreamedText content={content} isStreaming={false} />);

        const moneyElements = screen.getAllByTestId('chat-money');
        expect(moneyElements).toHaveLength(4);

        expect(moneyElements[0]).toHaveTextContent('3.70 SAR');
        expect(moneyElements[0]).toHaveAttribute('dir', 'ltr');

        expect(moneyElements[1]).toHaveTextContent('SAR 6.20');
        expect(moneyElements[1]).toHaveAttribute('dir', 'ltr');

        expect(moneyElements[2]).toHaveTextContent('٣.٧٠ SAR');
        expect(moneyElements[2]).toHaveAttribute('dir', 'ltr');

        expect(moneyElements[3]).toHaveTextContent('SAR ١٠٠,٠٠٠');
        expect(moneyElements[3]).toHaveAttribute('dir', 'ltr');
    });

    it('renders full Arabic reply with mixed Latin product names and prices keeping structure', () => {
        const reply = `الأسعار متغيرة حسب السوق، وهذه الأسعار الحالية:

Coins على PlayStation/Xbox:
100,000: 3.70 SAR، و500,000: 6.20 SAR، و1,000,000: 9.20 SAR بالتوصيل العادي.
100,000: 4.70 SAR، و500,000: 8.70 SAR، و1,000,000: 14.20 SAR بالتوصيل السريع.

للاطلاع على باقي الكميات والخدمات، السعر النهائي يظهر في صفحة المنتج.`;

        const { container } = render(
            <StreamedText content={reply} isStreaming={false} />,
        );

        const paragraphs = container.querySelectorAll('p');
        expect(paragraphs).toHaveLength(3);

        const moneyElements = screen.getAllByTestId('chat-money');
        expect(moneyElements).toHaveLength(6);
        expect(moneyElements.map((m) => m.textContent)).toEqual([
            '3.70 SAR',
            '6.20 SAR',
            '9.20 SAR',
            '4.70 SAR',
            '8.70 SAR',
            '14.20 SAR',
        ]);
        moneyElements.forEach((el) => {
            expect(el).toHaveAttribute('dir', 'ltr');
            expect(el).toHaveClass('font-semibold');
        });
    });

    it('renders partially typed **bold as literal text during streaming without errors', () => {
        const { container, rerender } = render(
            <StreamedText content="مرحبا **نص غير" isStreaming={true} />,
        );

        // Before closing ** arrives, the partial marker is visible literal text
        expect(container.textContent).toContain('**نص غير');
        expect(container.querySelector('strong')).toBeNull();

        // When closing ** arrives, it turns into bold
        rerender(
            <StreamedText
                content="مرحبا **نص مكتمل** الآن"
                isStreaming={true}
            />,
        );
        expect(container.querySelector('strong')).not.toBeNull();
        expect(container.querySelector('strong')?.textContent).toBe('نص مكتمل');
    });

    it('progressively animates newly arrived stream runs and drops animation when settled', () => {
        const { container, rerender } = render(
            <StreamedText content="السعر: " isStreaming={true} />,
        );

        expect(container.textContent).toContain('السعر: ');

        rerender(<StreamedText content="السعر: 3.70 SAR" isStreaming={true} />);
        const run = container.querySelector('.chat-stream-run');
        expect(run).not.toBeNull();
        expect(run?.textContent).toBe('3.70 SAR');

        // Settled reply drops .chat-stream-run wrapper
        rerender(
            <StreamedText content="السعر: 3.70 SAR" isStreaming={false} />,
        );
        expect(container.querySelector('.chat-stream-run')).toBeNull();
        expect(screen.getByTestId('chat-money')).toHaveTextContent('3.70 SAR');
    });

    it('renders raw HTML and <script> strictly as visible literal text rather than executable markup', () => {
        const malicious =
            '<script>alert("xss")</script><div class="injected">evil</div>';
        const { container } = render(
            <StreamedText content={malicious} isStreaming={false} />,
        );

        // No script or injected div elements must be created in the DOM
        expect(container.querySelector('script')).toBeNull();
        expect(container.querySelector('.injected')).toBeNull();

        // The text must be rendered as literal visible text
        expect(container.textContent).toBe(malicious);
    });
});
