import type React from 'react';
import { useState } from 'react';

type StreamedTextProps = {
    content: string;
    isStreaming: boolean;
};

type Shown = {
    content: string;
    committed: number;
};

/**
 * Renders assistant text so that each newly streamed run fades in instead of
 * flashing. The already-shown prefix stays static; only the appended tail
 * carries the entrance animation (reduced motion disables it in CSS).
 *
 * The committed length is derived state adjusted during render (React's
 * "adjust state when a prop changes" pattern), so no ref is read in render.
 */
export const StreamedText: React.FC<StreamedTextProps> = ({
    content,
    isStreaming,
}) => {
    const [shown, setShown] = useState<Shown>({ content, committed: 0 });

    if (shown.content !== content) {
        setShown({
            content,
            committed: isStreaming
                ? Math.min(shown.content.length, content.length)
                : content.length,
        });
    }

    if (!isStreaming) {
        return <>{content}</>;
    }

    const head = content.slice(0, shown.committed);
    const tail = content.slice(shown.committed);

    return (
        <>
            {head}
            {tail !== '' && (
                <span key={content.length} className="chat-stream-run">
                    {tail}
                </span>
            )}
        </>
    );
};
