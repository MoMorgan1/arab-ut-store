import { describe, expect, it } from 'vitest';
import { parseChatBlocks, parseInlineTokens } from '@/lib/chat-format';
import type {
    BoldToken,
    ChatBlock,
    ParagraphBlock,
    MoneyToken,
    TextToken,
} from '@/lib/chat-format';

/**
 * Narrows a block to a paragraph so its inline tokens are reachable. A list
 * block has no `tokens` of its own — its text lives on each item.
 */
function paragraphTokens(block: ChatBlock | undefined) {
    expect(block?.type).toBe('paragraph');

    return (block as ParagraphBlock).tokens;
}

describe('chat-format pure parser', () => {
    describe('parseChatBlocks (Block Detection)', () => {
        it('returns an empty array for empty or whitespace-only strings', () => {
            expect(parseChatBlocks('')).toEqual([]);
            expect(parseChatBlocks('   \n\n\t  ')).toEqual([]);
        });

        it('parses a single paragraph into one paragraph block', () => {
            const blocks = parseChatBlocks('مرحبا بك في متجر عرب التيميت');
            expect(blocks).toHaveLength(1);
            expect(blocks[0].type).toBe('paragraph');
            expect(blocks[0].raw).toBe('مرحبا بك في متجر عرب التيميت');
            expect(paragraphTokens(blocks[0])).toEqual([
                {
                    type: 'text',
                    value: 'مرحبا بك في متجر عرب التيميت',
                    start: 0,
                    end: 28,
                },
            ]);
        });

        it('splits paragraphs separated by blank lines', () => {
            const input =
                'الفقرة الأولى.\n\nالفقرة الثانية.\n\n\nالفقرة الثالثة.';
            const blocks = parseChatBlocks(input);

            expect(blocks).toHaveLength(3);
            expect(blocks.map((b) => b.type)).toEqual([
                'paragraph',
                'paragraph',
                'paragraph',
            ]);
            expect(blocks[0].raw).toBe('الفقرة الأولى.');
            expect(blocks[1].raw).toBe('الفقرة الثانية.');
            expect(blocks[2].raw).toBe('الفقرة الثالثة.');
        });

        it('preserves soft single newlines within a single paragraph', () => {
            const input = 'السطر الأول\nالسطر الثاني\nالسطر الثالث';
            const blocks = parseChatBlocks(input);

            expect(blocks).toHaveLength(1);
            expect(blocks[0].type).toBe('paragraph');
            expect(blocks[0].raw).toBe(input);
            expect(paragraphTokens(blocks[0])).toEqual([
                {
                    type: 'text',
                    value: input,
                    start: 0,
                    end: input.length,
                },
            ]);
        });

        it('detects unordered bullet list items with -, *, •, and unicode bullets', () => {
            const hyphenList = '- خيار أول\n- خيار ثاني';
            const asteriskList = '* خيار أول\n* خيار ثاني';
            const bulletList = '• خيار أول\n• خيار ثاني';
            const tightBulletList = '•خيار أول\n•خيار ثاني';

            for (const text of [
                hyphenList,
                asteriskList,
                bulletList,
                tightBulletList,
            ]) {
                const blocks = parseChatBlocks(text);
                expect(blocks).toHaveLength(1);
                expect(blocks[0].type).toBe('list');

                if (blocks[0].type === 'list') {
                    expect(blocks[0].ordered).toBe(false);
                    expect(blocks[0].items).toHaveLength(2);
                    expect(blocks[0].items[0].tokens[0]).toMatchObject({
                        type: 'text',
                        value: 'خيار أول',
                    });
                    expect(blocks[0].items[1].tokens[0]).toMatchObject({
                        type: 'text',
                        value: 'خيار ثاني',
                    });
                }
            }
        });

        it('detects ordered list items with Latin digits (N. and N))', () => {
            const dotList = '1. الخطوة الأولى\n2. الخطوة الثانية';
            const parenList = '1) الخطوة الأولى\n2) الخطوة الثانية';

            for (const text of [dotList, parenList]) {
                const blocks = parseChatBlocks(text);
                expect(blocks).toHaveLength(1);
                expect(blocks[0].type).toBe('list');

                if (blocks[0].type === 'list') {
                    expect(blocks[0].ordered).toBe(true);
                    expect(blocks[0].start).toBe(1);
                    expect(blocks[0].items).toHaveLength(2);
                    expect(blocks[0].items[0].tokens[0]).toMatchObject({
                        type: 'text',
                        value: 'الخطوة الأولى',
                    });
                    expect(blocks[0].items[1].tokens[0]).toMatchObject({
                        type: 'text',
                        value: 'الخطوة الثانية',
                    });
                }
            }
        });

        it('detects ordered list items with Eastern Arabic-Indic numerals (١. and ٢))', () => {
            const arabicNumbered = '١. الخيار الأول\n٢) الخيار الثاني';
            const blocks = parseChatBlocks(arabicNumbered);

            expect(blocks).toHaveLength(1);
            expect(blocks[0].type).toBe('list');

            if (blocks[0].type === 'list') {
                expect(blocks[0].ordered).toBe(true);
                expect(blocks[0].start).toBe(1);
                expect(blocks[0].items).toHaveLength(2);
                expect(blocks[0].items[0].tokens[0]).toMatchObject({
                    type: 'text',
                    value: 'الخيار الأول',
                });
                expect(blocks[0].items[1].tokens[0]).toMatchObject({
                    type: 'text',
                    value: 'الخيار الثاني',
                });
            }
        });

        it('groups consecutive items into a single list and handles surrounding paragraphs', () => {
            const input =
                'هذه مقدمة:\n- العنصر أ\n- العنصر ب\n- العنصر ج\n\nوهذه خاتمة.';
            const blocks = parseChatBlocks(input);

            expect(blocks).toHaveLength(3);
            expect(blocks[0].type).toBe('paragraph');
            expect(blocks[1].type).toBe('list');
            expect(blocks[2].type).toBe('paragraph');

            if (blocks[1].type === 'list') {
                expect(blocks[1].items).toHaveLength(3);
            }
        });

        it('correctly parses the real-world Arab UT pricing reply', () => {
            const input = `الأسعار متغيرة حسب السوق، وهذه الأسعار الحالية:

Coins على PlayStation/Xbox:
100,000: 3.70 SAR، و500,000: 6.20 SAR، و1,000,000: 9.20 SAR بالتوصيل العادي.
100,000: 4.70 SAR، و500,000: 8.70 SAR، و1,000,000: 14.20 SAR بالتوصيل السريع.

للاطلاع على باقي الكميات والخدمات، السعر النهائي يظهر في صفحة المنتج.`;

            const blocks = parseChatBlocks(input);

            expect(blocks).toHaveLength(3);
            expect(blocks[0].type).toBe('paragraph');
            expect(blocks[1].type).toBe('paragraph');
            expect(blocks[2].type).toBe('paragraph');

            // Paragraph 2 contains prices
            const p2Tokens = paragraphTokens(blocks[1]);
            const moneyTokens = p2Tokens.filter((t) => t.type === 'money');
            expect(moneyTokens).toHaveLength(6);
            expect(moneyTokens.map((m) => (m as MoneyToken).value)).toEqual([
                '3.70 SAR',
                '6.20 SAR',
                '9.20 SAR',
                '4.70 SAR',
                '8.70 SAR',
                '14.20 SAR',
            ]);
        });
    });

    describe('parseInlineTokens (Bold, Money, Security)', () => {
        it('parses inline bold markers **bold**', () => {
            const tokens = parseInlineTokens('هذا نص **مهم جدا** للتجربة');
            expect(tokens).toHaveLength(3);
            expect(tokens[0]).toMatchObject({ type: 'text', value: 'هذا نص ' });
            expect(tokens[1]).toMatchObject({
                type: 'bold',
                raw: '**مهم جدا**',
                children: [{ type: 'text', value: 'مهم جدا' }],
            });
            expect(tokens[2]).toMatchObject({
                type: 'text',
                value: ' للتجربة',
            });
        });

        it('renders a partial unclosed ** as literal text without throwing or breaking', () => {
            const partial = 'جاري كتابة **النص غير المكتمل';
            const tokens = parseInlineTokens(partial);

            expect(tokens).toHaveLength(1);
            expect(tokens[0]).toMatchObject({
                type: 'text',
                value: partial,
            });
        });

        it('handles multiple bold tokens in one line', () => {
            const input = '**الأول** و **الثاني** و **الثالث**';
            const tokens = parseInlineTokens(input);

            const boldTokens = tokens.filter(
                (t): t is BoldToken => t.type === 'bold',
            );
            expect(boldTokens).toHaveLength(3);
            expect(boldTokens[0].raw).toBe('**الأول**');
            expect(boldTokens[1].raw).toBe('**الثاني**');
            expect(boldTokens[2].raw).toBe('**الثالث**');
        });

        it('detects money tokens in Number-Currency order with thousands separators and decimals', () => {
            const cases = [
                '3.70 SAR',
                '100,000 SAR',
                '1,000,000.50 SAR',
                '500 USD',
                '1200 AED',
                '350 EGP',
                '3.70 sar',
                '500 usd',
                '3.70SAR',
                '500USD',
            ];

            for (const text of cases) {
                const tokens = parseInlineTokens(text);
                expect(tokens).toHaveLength(1);
                expect(tokens[0].type).toBe('money');
                expect((tokens[0] as MoneyToken).value).toBe(text);
            }
        });

        it('detects money tokens in Currency-Number order', () => {
            const cases = [
                'SAR 3.70',
                'SAR 100,000',
                'USD 500',
                'AED 1200',
                'EGP 350',
                'SAR3.70',
                'USD500',
            ];

            for (const text of cases) {
                const tokens = parseInlineTokens(text);
                expect(tokens).toHaveLength(1);
                expect(tokens[0].type).toBe('money');
                expect((tokens[0] as MoneyToken).value).toBe(text);
            }
        });

        it('detects money tokens in Eastern Arabic-Indic numerals in both orders', () => {
            const arabicCases = [
                '٣.٧٠ SAR',
                'SAR ٣.٧٠',
                '١٠٠,٠٠٠ SAR',
                'SAR ١٠٠,٠٠٠',
                '١٠٠٬٠٠٠٫٥٠ SAR',
                'SAR ١٠٠٬٠٠٠٫٥٠',
                '٥٠٠ USD',
                'USD ٥٠٠',
            ];

            for (const text of arabicCases) {
                const tokens = parseInlineTokens(text);
                expect(tokens).toHaveLength(1);
                expect(tokens[0].type).toBe('money');
                expect((tokens[0] as MoneyToken).value).toBe(text);
            }
        });

        it('isolates money tokens from adjacent Arabic and Latin punctuation', () => {
            const input = 'السعر (3.70 SAR)، أو 6.20 SAR. هل يناسبك؟';
            const tokens = parseInlineTokens(input);

            expect(tokens).toEqual([
                { type: 'text', value: 'السعر (', start: 0, end: 7 },
                {
                    type: 'money',
                    value: '3.70 SAR',
                    raw: '3.70 SAR',
                    start: 7,
                    end: 15,
                },
                { type: 'text', value: ')، أو ', start: 15, end: 21 },
                {
                    type: 'money',
                    value: '6.20 SAR',
                    raw: '6.20 SAR',
                    start: 21,
                    end: 29,
                },
                { type: 'text', value: '. هل يناسبك؟', start: 29, end: 41 },
            ]);
        });

        it('does not treat non-currency numbers or normal words as money tokens', () => {
            const input = 'الكمية 100,000 قطعة و SARAH اشترت 500 حبة';
            const tokens = parseInlineTokens(input);

            expect(tokens).toHaveLength(1);
            expect(tokens[0].type).toBe('text');
            expect((tokens[0] as TextToken).value).toBe(input);
        });

        it('supports nested tokens: bold containing money token **3.70 SAR**', () => {
            const input = 'السعر الخاص: **3.70 SAR** فقط';
            const tokens = parseInlineTokens(input);

            expect(tokens).toHaveLength(3);
            expect(tokens[0]).toMatchObject({
                type: 'text',
                value: 'السعر الخاص: ',
            });
            expect(tokens[1].type).toBe('bold');

            if (tokens[1].type === 'bold') {
                expect(tokens[1].children).toEqual([
                    {
                        type: 'money',
                        value: '3.70 SAR',
                        raw: '3.70 SAR',
                        start: 15,
                        end: 23,
                    },
                ]);
            }

            expect(tokens[2]).toMatchObject({ type: 'text', value: ' فقط' });
        });

        it('treats raw HTML and <script> tags strictly as literal text tokens', () => {
            const malicious =
                '<script>alert("xss")</script><img src="x" onerror="steal()" /><b>HTML</b>';
            const tokens = parseInlineTokens(malicious);

            expect(tokens).toHaveLength(1);
            expect(tokens[0].type).toBe('text');
            expect((tokens[0] as TextToken).value).toBe(malicious);
        });
    });
});
