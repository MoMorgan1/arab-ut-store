import React from 'react';

type TypingIndicatorProps = {
    locale?: string;
};

const DOT =
    'h-2 w-2 animate-bounce rounded-full bg-[var(--chat-accent)] motion-reduce:animate-none';

export const TypingIndicator: React.FC<TypingIndicatorProps> = () => {
    return (
        <div
            className="chat-bubble-enter flex items-center gap-1.5 rounded-2xl rounded-bl-sm border border-[var(--chat-line)] bg-[var(--chat-card)] px-4 py-3 shadow-[0_2px_8px_rgb(13_11_8/0.05)]"
            aria-hidden="true"
        >
            <span
                className={DOT}
                style={{ animationDelay: '0ms', animationDuration: '900ms' }}
            />
            <span
                className={DOT}
                style={{ animationDelay: '180ms', animationDuration: '900ms' }}
            />
            <span
                className={DOT}
                style={{ animationDelay: '360ms', animationDuration: '900ms' }}
            />
        </div>
    );
};
