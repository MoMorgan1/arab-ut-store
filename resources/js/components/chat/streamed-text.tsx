import type React from 'react';
import { useState } from 'react';
import { parseChatBlocks } from '@/lib/chat-format';
import type { ChatBlock, InlineToken } from '@/lib/chat-format';

export type StreamedTextProps = {
    content: string;
    isStreaming: boolean;
    showCaret?: boolean;
};

type Shown = {
    content: string;
    committed: number;
};

function renderInlineTokens(
    tokens: InlineToken[],
    committed: number,
    isStreaming: boolean,
): React.ReactNode[] {
    return tokens.map((token, idx) => {
        if (token.type === 'text') {
            const str = token.value;
            const start = token.start ?? 0;
            const end = token.end ?? start + str.length;

            // Fully-revealed plain text goes in as a bare string: wrapping it in
            // a span would add a DOM level for nothing, and the bubble's own
            // element should stay the text's direct parent.
            if (!isStreaming || end <= committed) {
                return str;
            }

            if (start >= committed) {
                return (
                    <span key={idx} className="chat-stream-run">
                        {str}
                    </span>
                );
            }

            const headLen = Math.max(0, committed - start);
            const head = str.slice(0, headLen);
            const tail = str.slice(headLen);

            return (
                <span key={idx}>
                    {head}
                    {tail !== '' && (
                        <span className="chat-stream-run">{tail}</span>
                    )}
                </span>
            );
        }

        if (token.type === 'money') {
            const start = token.start ?? 0;
            const end = token.end ?? start + token.raw.length;

            const moneyNode = (
                <span
                    dir="ltr"
                    data-testid="chat-money"
                    className="chat-money font-semibold text-[var(--chat-accent-ink)]"
                >
                    {token.value}
                </span>
            );

            if (!isStreaming || end <= committed) {
                return <span key={idx}>{moneyNode}</span>;
            }

            return (
                <span key={idx} className="chat-stream-run">
                    {moneyNode}
                </span>
            );
        }

        if (token.type === 'bold') {
            return (
                <strong key={idx} className="font-bold text-[var(--chat-ink)]">
                    {renderInlineTokens(token.children, committed, isStreaming)}
                </strong>
            );
        }

        return null;
    });
}

function renderBlock(
    block: ChatBlock,
    blockIndex: number,
    committed: number,
    isStreaming: boolean,
    isLastBlock: boolean,
    caret: React.ReactNode,
): React.ReactNode {
    if (block.type === 'paragraph') {
        return (
            <p
                key={blockIndex}
                className="leading-relaxed break-words whitespace-pre-wrap"
            >
                {renderInlineTokens(block.tokens, committed, isStreaming)}
                {isLastBlock && caret}
            </p>
        );
    }

    if (block.type === 'list') {
        const items = block.items.map((item, itemIdx) => {
            const isLastItem =
                isLastBlock && itemIdx === block.items.length - 1;

            return (
                <li key={itemIdx} className="leading-relaxed">
                    {renderInlineTokens(item.tokens, committed, isStreaming)}
                    {isLastItem && caret}
                </li>
            );
        });

        if (block.ordered) {
            return (
                <ol
                    key={blockIndex}
                    start={block.start}
                    className="my-1.5 list-decimal space-y-1 ps-5 break-words"
                >
                    {items}
                </ol>
            );
        }

        return (
            <ul
                key={blockIndex}
                className="my-1.5 list-disc space-y-1 ps-5 break-words"
            >
                {items}
            </ul>
        );
    }

    return null;
}

/**
 * Renders assistant text with structured formatting (paragraphs, lists, bold, money)
 * while progressively animating streaming deltas without flicker or layout jump.
 */
export const StreamedText: React.FC<StreamedTextProps> = ({
    content,
    isStreaming,
    showCaret = true,
}) => {
    // An assistant message exists before its first delta arrives, so the text
    // can legitimately be absent rather than empty.
    const text = typeof content === 'string' ? content : '';
    const [shown, setShown] = useState<Shown>({ content: text, committed: 0 });

    if (shown.content !== text) {
        setShown({
            content: text,
            committed: isStreaming
                ? Math.min(shown.content.length, text.length)
                : text.length,
        });
    }

    if (text === '') {
        return null;
    }

    const blocks = parseChatBlocks(text);
    const committed = isStreaming ? shown.committed : text.length;
    const caret =
        isStreaming && showCaret ? (
            <span aria-hidden="true" className="chat-stream-caret" />
        ) : null;

    if (blocks.length === 0) {
        return (
            <p className="leading-relaxed break-words whitespace-pre-wrap">
                {caret}
            </p>
        );
    }

    if (blocks.length === 1 && blocks[0].type === 'paragraph') {
        return (
            <p className="leading-relaxed break-words whitespace-pre-wrap">
                {renderInlineTokens(blocks[0].tokens, committed, isStreaming)}
                {caret}
            </p>
        );
    }

    if (blocks.length === 1 && blocks[0].type === 'list') {
        return renderBlock(blocks[0], 0, committed, isStreaming, true, caret);
    }

    return (
        <div className="space-y-2.5 break-words">
            {blocks.map((block, idx) =>
                renderBlock(
                    block,
                    idx,
                    committed,
                    isStreaming,
                    idx === blocks.length - 1,
                    caret,
                ),
            )}
        </div>
    );
};
