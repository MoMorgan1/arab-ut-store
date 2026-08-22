import { ArrowUp } from 'lucide-react';
import type React from 'react';
import { useEffect, useRef, useState } from 'react';

type ChatComposerProps = {
    disabled?: boolean;
    locale?: string;
    onSend: (content: string) => void;
    showDisclaimer?: boolean;
};

const MAX_LENGTH = 4000;

export const ChatComposer: React.FC<ChatComposerProps> = ({
    disabled = false,
    locale = 'ar',
    onSend,
    showDisclaimer = false,
}) => {
    const [content, setContent] = useState('');
    const textareaRef = useRef<HTMLTextAreaElement>(null);
    const isEn = locale === 'en';

    const placeholder = isEn ? 'Type a message…' : 'اكتب رسالتك هنا…';
    const inputLabel = isEn ? 'Type your message' : 'اكتب رسالتك';
    const sendLabel = isEn ? 'Send message' : 'إرسال الرسالة';
    const disclaimer = isEn
        ? 'AI assistant — may make mistakes. Verify important info.'
        : 'مساعد ذكي — قد يخطئ، تحقق من المعلومات المهمة';

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

    const hasText = content.trim().length > 0;
    const canSubmit = hasText && !disabled;

    return (
        <form
            onSubmit={handleSubmit}
            className="chat-composer--mobile-safe flex flex-col gap-2 border-t border-[var(--chat-line)] bg-[var(--chat-card)] p-3"
        >
            <div
                dir="ltr"
                className="relative flex items-end gap-2 rounded-2xl border border-[var(--chat-line-strong)] bg-[var(--chat-surface)] p-1.5 transition-[border-color,box-shadow] duration-150 focus-within:border-[var(--chat-accent)] focus-within:shadow-[0_0_0_2px_var(--chat-accent)] motion-reduce:transition-none"
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
                    className="max-h-32 min-h-[44px] flex-1 resize-none bg-transparent px-3 py-2.5 text-base text-[var(--chat-ink)] placeholder-[var(--chat-faint)] focus:outline-none disabled:opacity-60 lg:text-sm"
                />

                <button
                    type="submit"
                    disabled={!canSubmit}
                    aria-label={sendLabel}
                    className={`chat-press flex h-11 w-11 flex-shrink-0 items-center justify-center rounded-xl bg-[var(--chat-accent)] text-[var(--chat-hero)] transition-[transform,opacity] duration-200 [transition-timing-function:var(--chat-ease-spring)] focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--arabut-focus)] disabled:cursor-not-allowed motion-reduce:transition-none ${
                        hasText
                            ? 'scale-100 opacity-100'
                            : 'scale-90 opacity-40'
                    }`}
                >
                    <ArrowUp className="h-5 w-5" aria-hidden="true" />
                </button>
            </div>

            {content.length > 3500 && (
                <div className="text-end text-[11px] text-[var(--chat-faint)]">
                    {content.length} / {MAX_LENGTH}
                </div>
            )}

            {showDisclaimer && (
                <p className="chat-drop-in text-center text-[11px] leading-snug text-[var(--chat-faint)]">
                    {disclaimer}
                </p>
            )}
        </form>
    );
};
