import { Sparkles, X } from 'lucide-react';
import type React from 'react';

type ChatHeaderProps = {
    locale?: string;
    onClose: () => void;
};

export const ChatHeader: React.FC<ChatHeaderProps> = ({
    locale = 'ar',
    onClose,
}) => {
    const isEn = locale === 'en';
    const title = isEn ? 'Arab UT Assistant' : 'مساعد عرب التيميت';
    const subtitle = isEn ? 'Usually replies quickly' : 'عادة يرد فورًا';
    const closeLabel = isEn ? 'Close chat' : 'إغلاق الشات';

    return (
        <div className="flex items-center justify-between border-b border-[var(--arabut-line)] bg-[var(--arabut-navy-deep)]/90 px-4 py-3.5 backdrop-blur-md">
            <div className="flex items-center gap-3">
                {/* Avatar with status dot */}
                <div className="relative flex h-10 w-10 flex-shrink-0 items-center justify-center rounded-full border border-[var(--arabut-line)] bg-[var(--arabut-navy-raised)] text-[var(--arabut-gold-bright)] shadow-inner">
                    <Sparkles className="h-5 w-5" aria-hidden="true" />
                    <span
                        className="absolute end-0 bottom-0 h-3 w-3 rounded-full border-2 border-[var(--arabut-navy-deep)] bg-emerald-500"
                        title={isEn ? 'Online' : 'متصل'}
                        aria-hidden="true"
                    />
                </div>

                <div className="flex flex-col text-start">
                    <h2 className="text-base leading-tight font-bold text-[var(--arabut-ink)]">
                        {title}
                    </h2>
                    <p className="text-xs leading-tight text-[var(--arabut-muted)]">
                        {subtitle}
                    </p>
                </div>
            </div>

            {/* Close / minimize button */}
            <button
                type="button"
                onClick={onClose}
                aria-label={closeLabel}
                className="flex h-11 w-11 items-center justify-center rounded-xl text-[var(--arabut-muted)] transition-colors hover:bg-[var(--arabut-navy-active)] hover:text-[var(--arabut-ink)] focus-visible:outline focus-visible:outline-2 focus-visible:outline-[var(--arabut-focus)]"
            >
                <X className="h-5 w-5" />
            </button>
        </div>
    );
};
