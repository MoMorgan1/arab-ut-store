import { MessageSquare, X } from 'lucide-react';
import React from 'react';

type ChatLauncherProps = {
    isOpen: boolean;
    unreadCount: number;
    locale?: string;
    onToggle: () => void;
};

export const ChatLauncher = React.forwardRef<
    HTMLButtonElement,
    ChatLauncherProps
>(({ isOpen, unreadCount, locale = 'ar', onToggle }, ref) => {
    const isEn = locale === 'en';
    const label = isOpen
        ? isEn
            ? 'Close chat'
            : 'إغلاق الشات'
        : isEn
          ? 'Open chat'
          : 'فتح الشات';

    return (
        <button
            ref={ref}
            type="button"
            onClick={onToggle}
            aria-expanded={isOpen}
            aria-haspopup="dialog"
            aria-label={label}
            className={`relative flex h-14 w-14 cursor-pointer items-center justify-center overflow-visible rounded-full border border-[var(--arabut-gold)]/45 bg-[color:color-mix(in_srgb,var(--arabut-navy-raised)_88%,transparent)] text-[var(--arabut-gold-bright)] shadow-[0_8px_24px_rgba(0,0,0,0.32)] backdrop-blur-md transition-[transform,background-color,border-color,box-shadow] duration-200 [transition-timing-function:cubic-bezier(0.25,1,0.5,1)] hover:-translate-y-0.5 hover:border-[var(--arabut-gold)]/70 hover:bg-[color:color-mix(in_srgb,var(--arabut-navy-active)_92%,transparent)] hover:shadow-[0_10px_28px_rgba(0,0,0,0.38)] focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-[var(--arabut-focus)] active:translate-y-0 active:scale-[0.97] motion-reduce:transform-none motion-reduce:transition-none sm:h-[60px] sm:w-[60px] ${isOpen ? 'chat-launcher-open' : ''}`}
        >
            <span className="sr-only">{label}</span>

            <span
                className="relative z-10 grid h-7 w-7 place-items-center"
                aria-hidden="true"
            >
                <span
                    className={`absolute h-6 w-6 transition-[opacity,transform] duration-150 motion-reduce:transition-none ${
                        isOpen
                            ? 'scale-75 rotate-45 opacity-0'
                            : 'scale-100 rotate-0 opacity-100'
                    }`}
                >
                    <MessageSquare className="h-6 w-6 stroke-2" />
                </span>
                <span
                    className={`absolute h-6 w-6 transition-[opacity,transform] duration-150 motion-reduce:transition-none ${
                        isOpen
                            ? 'scale-100 rotate-0 opacity-100'
                            : 'scale-75 -rotate-45 opacity-0'
                    }`}
                >
                    <X className="h-6 w-6 stroke-2" />
                </span>
            </span>

            {/* Unread badge */}
            {!isOpen && unreadCount > 0 && (
                <span
                    className="chat-pop-in absolute -top-1 -right-1 flex h-5 min-w-5 items-center justify-center rounded-full border border-[var(--arabut-gold-bright)] bg-[var(--arabut-navy-deep)] px-1 text-xs font-black text-[var(--arabut-gold-bright)] shadow-lg motion-reduce:animate-none"
                    aria-label={
                        isEn
                            ? `${unreadCount} unread messages`
                            : `${unreadCount} رسائل غير مقروءة`
                    }
                >
                    {unreadCount > 9 ? '+9' : unreadCount}
                </span>
            )}
        </button>
    );
});

ChatLauncher.displayName = 'ChatLauncher';
