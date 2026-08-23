export type TextToken = {
    type: 'text';
    value: string;
    start?: number;
    end?: number;
};

export type MoneyToken = {
    type: 'money';
    value: string;
    raw: string;
    start?: number;
    end?: number;
};

export type BoldToken = {
    type: 'bold';
    children: InlineToken[];
    raw: string;
    start?: number;
    end?: number;
};

export type InlineToken = TextToken | BoldToken | MoneyToken;

export type ParagraphBlock = {
    type: 'paragraph';
    tokens: InlineToken[];
    raw: string;
    start?: number;
    end?: number;
};

export type ListItem = {
    tokens: InlineToken[];
    raw: string;
    start?: number;
    end?: number;
};

export type ListBlock = {
    type: 'list';
    ordered: boolean;
    start?: number;
    items: ListItem[];
    raw: string;
    startIndex?: number;
    endIndex?: number;
};

export type ChatBlock = ParagraphBlock | ListBlock;

// Digits: Latin 0-9 and Eastern Arabic-Indic ٠-٩ (\u0660-\u0669)
const NUM_DIGITS = '[0-9\\u0660-\\u0669]';
// Thousands separators: comma or Arabic thousands separator \u066C (٬)
const THOUSANDS_SEP = '[,\\u066C]';
// Decimal separators: dot or Arabic decimal separator \u066B (٫)
const DECIMAL_SEP = '[.\\u066B]';

// Numbers with optional thousands separators and optional decimals
const NUMBER_PATTERN =
    '(?:' +
    NUM_DIGITS +
    '{1,3}(?:' +
    THOUSANDS_SEP +
    NUM_DIGITS +
    '{3})+(?:' +
    DECIMAL_SEP +
    NUM_DIGITS +
    '+)?|' +
    NUM_DIGITS +
    '+(?:' +
    DECIMAL_SEP +
    NUM_DIGITS +
    '+)?)';

const CURRENCY_PATTERN = '(?:SAR|USD|AED|EGP|sar|usd|aed|egp)';

// Order 1: 3.70 SAR or 100,000 SAR
const ORDER_NUM_CURR = NUMBER_PATTERN + '\\s*' + CURRENCY_PATTERN;
// Order 2: SAR 3.70 or SAR 100,000
const ORDER_CURR_NUM = CURRENCY_PATTERN + '\\s*' + NUMBER_PATTERN;

const MONEY_REGEX_SOURCE =
    '(?:^|[^\\p{L}\\p{N}])(' +
    ORDER_NUM_CURR +
    '|' +
    ORDER_CURR_NUM +
    ')(?=$|[^\\p{L}\\p{N}])';

function createMoneyRegex(): RegExp {
    return new RegExp(MONEY_REGEX_SOURCE, 'gu');
}

const BOLD_REGEX = /\*\*(.+?)\*\*/g;

function mergeAdjacentTextTokens(tokens: InlineToken[]): InlineToken[] {
    const merged: InlineToken[] = [];

    for (const token of tokens) {
        if (token.type === 'text' && token.value === '') {
            continue;
        }

        const prev = merged[merged.length - 1];

        if (prev && prev.type === 'text' && token.type === 'text') {
            prev.value += token.value;

            if (token.end !== undefined) {
                prev.end = token.end;
            }
        } else {
            merged.push({ ...token });
        }
    }

    return merged;
}

/**
 * Parses inline string into typed tokens: text, bold, and money.
 * @param text The string to parse.
 * @param baseOffset Optional character offset within the parent document.
 */
export function parseInlineTokens(
    text: string,
    baseOffset: number = 0,
): InlineToken[] {
    if (!text) {
        return [];
    }

    const tokens: InlineToken[] = [];
    let currentIndex = 0;

    function findNextMoney(fromIndex: number) {
        const regex = createMoneyRegex();
        const sub = text.slice(fromIndex);
        const m = regex.exec(sub);

        if (!m || m[1] === undefined) {
            return null;
        }

        const leadLen = m[0].length - m[1].length;
        const start = fromIndex + m.index + leadLen;
        const value = m[1];
        const end = start + value.length;

        return { start, end, value };
    }

    function findNextBold(fromIndex: number) {
        BOLD_REGEX.lastIndex = fromIndex;
        const m = BOLD_REGEX.exec(text);

        if (!m) {
            return null;
        }

        const start = m.index;
        const end = m.index + m[0].length;
        const content = m[1];
        const raw = m[0];

        return { start, end, content, raw };
    }

    while (currentIndex < text.length) {
        const nextBold = findNextBold(currentIndex);
        const nextMoney = findNextMoney(currentIndex);

        if (!nextBold && !nextMoney) {
            tokens.push({
                type: 'text',
                value: text.slice(currentIndex),
                start: baseOffset + currentIndex,
                end: baseOffset + text.length,
            });
            break;
        }

        const boldFirst =
            nextBold !== null &&
            (nextMoney === null || nextBold.start <= nextMoney.start);

        if (boldFirst && nextBold) {
            if (nextBold.start > currentIndex) {
                const leading = text.slice(currentIndex, nextBold.start);
                tokens.push(
                    ...parseInlineTokens(leading, baseOffset + currentIndex),
                );
            }

            const innerTokens = parseInlineTokens(
                nextBold.content,
                baseOffset + nextBold.start + 2,
            );
            tokens.push({
                type: 'bold',
                children:
                    innerTokens.length > 0
                        ? innerTokens
                        : [
                              {
                                  type: 'text',
                                  value: nextBold.content,
                                  start: baseOffset + nextBold.start + 2,
                                  end: baseOffset + nextBold.end - 2,
                              },
                          ],
                raw: nextBold.raw,
                start: baseOffset + nextBold.start,
                end: baseOffset + nextBold.end,
            });
            currentIndex = nextBold.end;
        } else if (nextMoney) {
            if (nextMoney.start > currentIndex) {
                tokens.push({
                    type: 'text',
                    value: text.slice(currentIndex, nextMoney.start),
                    start: baseOffset + currentIndex,
                    end: baseOffset + nextMoney.start,
                });
            }

            tokens.push({
                type: 'money',
                value: nextMoney.value,
                raw: nextMoney.value,
                start: baseOffset + nextMoney.start,
                end: baseOffset + nextMoney.end,
            });
            currentIndex = nextMoney.end;
        }
    }

    return mergeAdjacentTextTokens(tokens);
}

function parseArabicOrLatinInt(numStr: string): number {
    const normalized = numStr.replace(/[٠-٩]/g, (d) =>
        String('٠١٢٣٤٥٦٧٨٩'.indexOf(d)),
    );

    return parseInt(normalized, 10) || 1;
}

/**
 * Parses full reply text into structured blocks: paragraphs and lists.
 */
export function parseChatBlocks(text: string): ChatBlock[] {
    if (!text || text.trim() === '') {
        return [];
    }

    const lines = text.split(/\r?\n/);
    const blocks: ChatBlock[] = [];

    let currentParagraph: {
        lines: string[];
        start: number;
    } | null = null;

    let currentList: {
        ordered: boolean;
        // The number an ordered list starts counting from, distinct from
        // `start` below, which is this block's offset in the source text.
        startNumber?: number;
        items: Array<{
            itemText: string;
            raw: string;
            itemStart: number;
            lineStart: number;
        }>;
        rawLines: string[];
        start: number;
    } | null = null;

    function flushParagraph() {
        if (currentParagraph && currentParagraph.lines.length > 0) {
            const raw = currentParagraph.lines.join('\n');
            blocks.push({
                type: 'paragraph',
                tokens: parseInlineTokens(raw, currentParagraph.start),
                raw,
                start: currentParagraph.start,
                end: currentParagraph.start + raw.length,
            });
            currentParagraph = null;
        }
    }

    function flushList() {
        if (currentList) {
            const raw = currentList.rawLines.join('\n');
            const items: ListItem[] = currentList.items.map((it) => ({
                tokens: parseInlineTokens(it.itemText, it.itemStart),
                raw: it.raw,
                start: it.lineStart,
                end: it.lineStart + it.raw.length,
            }));
            blocks.push({
                type: 'list',
                ordered: currentList.ordered,
                start: currentList.startNumber,
                items,
                raw,
                startIndex: currentList.start,
                endIndex: currentList.start + raw.length,
            });
            currentList = null;
        }
    }

    let lineOffset = 0;

    for (let i = 0; i < lines.length; i++) {
        const line = lines[i];
        const lineStart = lineOffset;
        const lineLen = line.length;
        // Check newline length in original string
        const nextNewline = text.startsWith('\r\n', lineStart + lineLen)
            ? 2
            : 1;
        lineOffset =
            lineStart + lineLen + (i < lines.length - 1 ? nextNewline : 0);

        // 1. Blank line
        if (/^\s*$/.test(line)) {
            flushParagraph();
            flushList();
            continue;
        }

        // 2. Bullet list line (-, *, •)
        const bulletMatch = /^\s*(?:([-*])\s+(.*)|([-*])$|(•)\s*(.*))$/.exec(
            line,
        );

        if (bulletMatch) {
            flushParagraph();
            const itemText = bulletMatch[2] ?? bulletMatch[5] ?? '';
            // Compute where item text begins in this line
            const matchIndex = line.indexOf(itemText);
            const itemStart =
                matchIndex >= 0
                    ? lineStart + matchIndex
                    : lineStart + line.length;

            if (currentList && !currentList.ordered) {
                currentList.items.push({
                    itemText,
                    raw: line,
                    itemStart,
                    lineStart,
                });
                currentList.rawLines.push(line);
            } else {
                flushList();
                currentList = {
                    ordered: false,
                    items: [{ itemText, raw: line, itemStart, lineStart }],
                    rawLines: [line],
                    start: lineStart,
                };
            }

            continue;
        }

        // 3. Ordered list line (1. or 1) or Arabic numerals ١. or ١))
        const orderedMatch = /^\s*([0-9]+|[٠-٩]+)[.)](?:\s+(.*)|\s*$)/.exec(
            line,
        );

        if (orderedMatch) {
            flushParagraph();
            const numStr = orderedMatch[1];
            const itemText = orderedMatch[2] ?? '';
            const startNum = parseArabicOrLatinInt(numStr);
            const matchIndex =
                itemText !== '' ? line.indexOf(itemText) : line.length;
            const itemStart = lineStart + matchIndex;

            if (currentList && currentList.ordered) {
                currentList.items.push({
                    itemText,
                    raw: line,
                    itemStart,
                    lineStart,
                });
                currentList.rawLines.push(line);
            } else {
                flushList();
                currentList = {
                    ordered: true,
                    startNumber: startNum,
                    items: [{ itemText, raw: line, itemStart, lineStart }],
                    rawLines: [line],
                    start: lineStart,
                };
            }

            continue;
        }

        // 4. Normal text line
        flushList();

        if (!currentParagraph) {
            currentParagraph = {
                lines: [line],
                start: lineStart,
            };
        } else {
            currentParagraph.lines.push(line);
        }
    }

    flushParagraph();
    flushList();

    return blocks;
}
