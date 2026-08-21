import { Send } from 'lucide-react';
import type React from 'react';
import { useEffect, useRef, useState } from 'react';

type ChatComposerProps = {
    disabled?: boolean;
    locale?: string;
    onSend: (content: string) => void;
};

const MAX_LENGTH = 4000;

export const ChatComposer: React.FC<ChatComposerProps> = ({
    disabled = false,
    locale = 'ar',
    onSend,
}) => {
    const [content, setContent] = useState('');
    const textareaRef = useRef<HTMLTextAreaElement>(null);
    const isEn = locale === 'en';

    const placeholder = isEn ? 'Type a message...' : 'اكتب رسالتك هنا...';
    const inputLabel = isEn ? 'Type your message' : 'اكتب رسالتك';
    const sendLabel = isEn ? 'Send message' : 'إرسال الرسالة';

    useEffect(() => {
        if (textareaRef.current) {
            textareaRef.current.style.height = 'auto';
            textareaRef.current.style.height = `${Math.min(textareaRef.current.scrollHeight, 120)}px`;
        }
    }, [content]);

    const handleSubmit = (e?: React.FormEvent) => {
        if (e) {
            e.preventDefault();
        }

        const trimmed = content.trim();

        if (trimmed === '' || disabled) {
            return;
        }

        onSend(trimmed);
        setContent('');

        if (textareaRef.current) {
            textareaRef.current.style.height = 'auto';
            textareaRef.current.focus();
        }
    };

    const handleKeyDown = (e: React.KeyboardEvent<HTMLTextAreaElement>) => {
        if (e.key === 'Enter' && !e.shiftKey) {
            e.preventDefault();
            handleSubmit();
        }
    };

    const canSubmit = content.trim().length > 0 && !disabled;

    return (
        <form
            onSubmit={handleSubmit}
            className="chat-composer--mobile-safe border-t border-[var(--arabut-line)] bg-[var(--arabut-navy-deep)]/90 p-3 backdrop-blur-md"
        >
            <div
                dir="ltr"
                className="relative flex items-end gap-2 rounded-2xl border border-[var(--arabut-line)] bg-[var(--arabut-navy-raised)] p-1.5 focus-within:border-[var(--arabut-gold)]/60 focus-within:ring-1 focus-within:ring-[var(--arabut-gold)]/60"
            >
                <textarea
                    ref={textareaRef}
                    value={content}
                    onChange={(e) => setContent(e.target.value)}
                    onKeyDown={handleKeyDown}
                    placeholder={placeholder}
                    rows={1}
                    maxLength={MAX_LENGTH}
                    disabled={disabled}
                    aria-label={inputLabel}
                    dir="auto"
                    className="max-h-32 min-h-[44px] flex-1 resize-none bg-transparent px-3 py-2.5 text-base text-[var(--arabut-ink)] placeholder-[var(--arabut-muted)] focus:outline-none disabled:opacity-60 lg:text-sm"
                />

                <button
                    type="submit"
                    disabled={!canSubmit}
                    aria-label={sendLabel}
                    className="flex h-11 w-11 flex-shrink-0 items-center justify-center rounded-xl bg-[var(--arabut-gold-bright)] text-[var(--arabut-navy-deep)] transition-opacity hover:opacity-90 focus-visible:outline focus-visible:outline-2 focus-visible:outline-[var(--arabut-focus)] disabled:cursor-not-allowed disabled:opacity-40"
                >
                    <Send className="h-5 w-5" aria-hidden="true" />
                </button>
            </div>

            {content.length > 3500 && (
                <div className="mt-1 text-end text-[11px] text-[var(--arabut-muted)]">
                    {content.length} / {MAX_LENGTH}
                </div>
            )}
        </form>
    );
};
