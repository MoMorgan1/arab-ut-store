import { MessageSquarePlus, Sparkles, X } from 'lucide-react';
import type React from 'react';

type ChatHeaderProps = {
    canRestart: boolean;
    closeButtonRef?: React.Ref<HTMLButtonElement>;
    isRestarting: boolean;
    locale?: string;
    onClose: () => void;
    onRestart: () => void;
};

export const ChatHeader: React.FC<ChatHeaderProps> = ({
    canRestart,
    closeButtonRef,
    isRestarting,
    locale = 'ar',
    onClose,
    onRestart,
}) => {
    const isEn = locale === 'en';
    const title = isEn ? 'Arab UT Assistant' : 'مساعد عرب التيميت';
    const subtitle = isEn ? 'Usually replies quickly' : 'عادة يرد فورًا';
    const closeLabel = isEn ? 'Close chat' : 'إغلاق الشات';
    const restartLabel = isRestarting
        ? isEn
            ? 'Starting new conversation...'
            : 'جاري بدء محادثة جديدة...'
        : isEn
          ? 'New conversation'
          : 'محادثة جديدة';
    const restartTooltipId = 'chat-restart-tooltip';

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

            <div className="flex flex-shrink-0 items-center gap-2">
                <div className="group relative">
                    <button
                        type="button"
                        onClick={onRestart}
                        disabled={!canRestart}
                        aria-busy={isRestarting}
                        aria-describedby={restartTooltipId}
                        aria-label={restartLabel}
                        className="flex h-11 w-11 items-center justify-center rounded-xl text-[var(--arabut-muted)] transition-colors hover:bg-[var(--arabut-navy-active)] hover:text-[var(--arabut-ink)] focus-visible:outline focus-visible:outline-2 focus-visible:outline-[var(--arabut-focus)] disabled:cursor-not-allowed disabled:opacity-45"
                    >
                        <MessageSquarePlus
                            aria-hidden="true"
                            className={`h-5 w-5 ${
                                isRestarting
                                    ? 'animate-pulse motion-reduce:animate-none'
                                    : ''
                            }`}
                        />
                    </button>
                    <span
                        id={restartTooltipId}
                        role="tooltip"
                        className="pointer-events-none absolute end-0 top-full z-10 mt-2 w-max max-w-48 rounded-lg border border-[var(--arabut-line)] bg-[var(--arabut-navy-raised)] px-2.5 py-1.5 text-xs text-[var(--arabut-ink)] opacity-0 shadow-lg transition-opacity group-focus-within:opacity-100 group-hover:opacity-100 motion-reduce:transition-none"
                    >
                        {restartLabel}
                    </span>
                </div>

                {/* Close / minimize button */}
                <button
                    ref={closeButtonRef}
                    type="button"
                    onClick={onClose}
                    aria-label={closeLabel}
                    className="flex h-11 w-11 items-center justify-center rounded-xl text-[var(--arabut-muted)] transition-colors hover:bg-[var(--arabut-navy-active)] hover:text-[var(--arabut-ink)] focus-visible:outline focus-visible:outline-2 focus-visible:outline-[var(--arabut-focus)]"
                >
                    <X aria-hidden="true" className="h-5 w-5" />
                </button>
            </div>
        </div>
    );
};
