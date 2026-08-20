import { MessageSquarePlus, Sparkles, X } from 'lucide-react';
import type React from 'react';

type ChatHeaderProps = {
    locale?: string;
    canRestart: boolean;
    isRestarting: boolean;
    onClose: () => void;
    onRestart: () => void;
};

export const ChatHeader: React.FC<ChatHeaderProps> = ({
    locale = 'ar',
    canRestart,
    isRestarting,
    onClose,
    onRestart,
}) => {
    const isEn = locale === 'en';
    const title = isEn ? 'Arab UT Assistant' : 'مساعد عرب التيميت';
    const subtitle = isEn ? 'Usually replies quickly' : 'عادة يرد فورًا';
    const closeLabel = isEn ? 'Close chat' : 'إغلاق الشات';
    const restartLabel = isEn ? 'New conversation' : 'محادثة جديدة';

    return (
        <div className="flex items-center justify-between border-b border-[var(--arabut-line)] bg-[var(--arabut-navy-deep)]/90 px-4 py-3.5 backdrop-blur-md">
            <div className="flex min-w-0 items-center gap-3">
                {/* Avatar with status dot */}
                <div className="relative flex h-10 w-10 flex-shrink-0 items-center justify-center rounded-full border border-[var(--arabut-line)] bg-[var(--arabut-navy-raised)] text-[var(--arabut-gold-bright)] shadow-inner">
                    <Sparkles className="h-5 w-5" aria-hidden="true" />
                    <span
                        className="absolute end-0 bottom-0 h-3 w-3 rounded-full border-2 border-[var(--arabut-navy-deep)] bg-emerald-500"
                        title={isEn ? 'Online' : 'متصل'}
                        aria-hidden="true"
                    />
                </div>

                <div className="flex min-w-0 flex-col text-start">
                    <h2 className="truncate text-base leading-tight font-bold text-[var(--arabut-ink)]">
                        {title}
                    </h2>
                    <p className="truncate text-xs leading-tight text-[var(--arabut-muted)]">
                        {subtitle}
                    </p>
                </div>
            </div>

            <div className="ms-2 flex flex-shrink-0 items-center gap-2">
                <div className="group relative flex">
                    <button
                        type="button"
                        onClick={onRestart}
                        disabled={!canRestart}
                        aria-label={restartLabel}
                        aria-busy={isRestarting}
                        aria-describedby="chat-restart-tooltip"
                        title={restartLabel}
                        className="flex h-11 w-11 items-center justify-center rounded-xl text-[var(--arabut-muted)] transition-colors hover:bg-[var(--arabut-navy-active)] hover:text-[var(--arabut-ink)] focus-visible:outline focus-visible:outline-2 focus-visible:outline-[var(--arabut-focus)] disabled:cursor-not-allowed disabled:opacity-40 motion-reduce:transition-none"
                    >
                        <MessageSquarePlus
                            className={`h-5 w-5 ${isRestarting ? 'animate-pulse motion-reduce:animate-none' : ''}`}
                            aria-hidden="true"
                        />
                    </button>
                    <span
                        id="chat-restart-tooltip"
                        role="tooltip"
                        className="pointer-events-none invisible absolute end-0 top-full z-10 mt-2 w-max max-w-[min(12rem,calc(100vw-2rem))] rounded-lg border border-[var(--arabut-line)] bg-[var(--arabut-navy-raised)] px-2.5 py-1.5 text-xs font-semibold whitespace-normal text-[var(--arabut-ink)] opacity-0 shadow-lg transition-opacity group-focus-within:visible group-focus-within:opacity-100 group-hover:visible group-hover:opacity-100 motion-reduce:transition-none"
                    >
                        {restartLabel}
                    </span>
                </div>

                {/* Close / minimize remains a separate action. */}
                <button
                    type="button"
                    onClick={onClose}
                    aria-label={closeLabel}
                    className="flex h-11 w-11 items-center justify-center rounded-xl text-[var(--arabut-muted)] transition-colors hover:bg-[var(--arabut-navy-active)] hover:text-[var(--arabut-ink)] focus-visible:outline focus-visible:outline-2 focus-visible:outline-[var(--arabut-focus)] motion-reduce:transition-none"
                >
                    <X className="h-5 w-5" aria-hidden="true" />
                </button>
            </div>
        </div>
    );
};
