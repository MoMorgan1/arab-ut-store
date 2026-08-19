import React from 'react';

type TypingIndicatorProps = {
    locale?: string;
};

export const TypingIndicator: React.FC<TypingIndicatorProps> = () => {
    return (
        <div
            className="flex items-center gap-1.5 rounded-2xl rounded-bl-sm border border-[var(--arabut-line)] bg-[var(--arabut-navy-raised)] px-4 py-3 text-[var(--arabut-ink)] shadow-md"
            aria-hidden="true"
        >
            <span
                className="h-2 w-2 animate-bounce rounded-full bg-[var(--arabut-gold-bright)] motion-reduce:animate-none"
                style={{ animationDelay: '0ms', animationDuration: '900ms' }}
            />
            <span
                className="h-2 w-2 animate-bounce rounded-full bg-[var(--arabut-gold-bright)] motion-reduce:animate-none"
                style={{ animationDelay: '180ms', animationDuration: '900ms' }}
            />
            <span
                className="h-2 w-2 animate-bounce rounded-full bg-[var(--arabut-gold-bright)] motion-reduce:animate-none"
                style={{ animationDelay: '360ms', animationDuration: '900ms' }}
            />
        </div>
    );
};
