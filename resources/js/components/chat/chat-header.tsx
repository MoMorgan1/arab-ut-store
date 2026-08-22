import { ChevronLeft, MessageSquarePlus, Sparkles, X } from 'lucide-react';
import type React from 'react';

type ChatHeaderProps = {
    canRestart: boolean;
    closeButtonRef?: React.Ref<HTMLButtonElement>;
    isRestarting: boolean;
    locale?: string;
    onBack: () => void;
    onClose: () => void;
    onRestart: () => void;
};

export const ChatHeader: React.FC<ChatHeaderProps> = ({
    canRestart,
    closeButtonRef,
    isRestarting,
    locale = 'ar',
    onBack,
    onClose,
    onRestart,
}) => {
    const isEn = locale === 'en';
    const title = isEn ? 'Arab UT Assistant' : 'مساعد عرب التيميت';
    const subtitle = isEn ? 'Usually replies instantly' : 'عادة نرد فورًا';
    const backLabel = isEn ? 'Back' : 'رجوع';
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
        <div className="flex items-center justify-between gap-2 border-b border-[var(--chat-line)] bg-[var(--chat-card)] px-3 py-2.5">
            <div className="flex items-center gap-2">
                <button
                    type="button"
                    onClick={onBack}
                    aria-label={backLabel}
                    className="chat-back-button chat-press flex h-11 w-11 items-center justify-center rounded-xl text-[var(--chat-muted)] transition-colors hover:bg-[var(--chat-tint)] hover:text-[var(--chat-ink)] focus-visible:outline focus-visible:outline-2 focus-visible:outline-[var(--arabut-focus)]"
                >
                    <ChevronLeft
                        aria-hidden="true"
                        className="h-5 w-5 transition-transform duration-150 group-hover:-translate-x-0.5 rtl:-scale-x-100"
                    />
                </button>

                <div className="relative flex h-9 w-9 flex-shrink-0 items-center justify-center rounded-full bg-[var(--chat-tint)] text-[var(--chat-accent-ink)]">
                    <Sparkles
                        className="h-[18px] w-[18px]"
                        aria-hidden="true"
                    />
                    <span
                        className="absolute end-[-1px] bottom-[-1px] h-2.5 w-2.5 rounded-full border-2 border-[var(--chat-card)] bg-[var(--chat-success)]"
                        title={isEn ? 'Online' : 'متصل'}
                        aria-hidden="true"
                    />
                </div>

                <div className="flex flex-col text-start">
                    <h2 className="text-[15px] leading-tight font-semibold text-[var(--chat-ink)]">
                        {title}
                    </h2>
                    <p className="text-xs leading-tight text-[var(--chat-muted)]">
                        {subtitle}
                    </p>
                </div>
            </div>

            <div className="flex flex-shrink-0 items-center gap-1">
                <div className="chat-restart-group group relative">
                    <button
                        type="button"
                        onClick={onRestart}
                        disabled={!canRestart}
                        aria-busy={isRestarting}
                        aria-describedby={restartTooltipId}
                        aria-label={restartLabel}
                        className="chat-restart-button chat-press flex h-11 w-11 items-center justify-center rounded-xl text-[var(--chat-muted)] transition-colors hover:bg-[var(--chat-tint)] hover:text-[var(--chat-ink)] focus-visible:outline focus-visible:outline-2 focus-visible:outline-[var(--arabut-focus)] disabled:cursor-not-allowed disabled:opacity-45"
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
                        className="chat-restart-tooltip pointer-events-none absolute end-0 top-full z-30 mt-2 w-max max-w-48 rounded-lg border border-[var(--chat-line)] bg-[var(--chat-card)] px-2.5 py-1.5 text-xs text-[var(--chat-ink)] shadow-lg"
                    >
                        {restartLabel}
                    </span>
                </div>

                <button
                    ref={closeButtonRef}
                    type="button"
                    onClick={onClose}
                    aria-label={closeLabel}
                    className="chat-press flex h-11 w-11 items-center justify-center rounded-xl text-[var(--chat-muted)] transition-colors hover:bg-[var(--chat-tint)] hover:text-[var(--chat-ink)] focus-visible:outline focus-visible:outline-2 focus-visible:outline-[var(--arabut-focus)]"
                >
                    <X aria-hidden="true" className="h-5 w-5" />
                </button>
            </div>
        </div>
    );
};
