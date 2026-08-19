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
            className="group relative flex h-14 w-14 items-center justify-center rounded-full border border-[var(--arabut-line)] bg-[var(--arabut-navy-raised)] text-[var(--arabut-gold-bright)] shadow-2xl transition-transform duration-200 ease-out hover:scale-105 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-[var(--arabut-focus)] active:scale-95 motion-reduce:transform-none"
        >
            <span className="sr-only">{label}</span>

            {/* Glowing gold ambient effect */}
            <span
                className="absolute inset-0 rounded-full bg-[var(--arabut-gold)] opacity-15 blur-md transition-opacity duration-200 group-hover:opacity-30 motion-reduce:hidden"
                aria-hidden="true"
            />

            {isOpen ? (
                <X className="relative z-10 h-6 w-6 transition-transform duration-150 motion-reduce:transform-none" />
            ) : (
                <MessageSquare className="relative z-10 h-6 w-6 transition-transform duration-150 motion-reduce:transform-none" />
            )}

            {/* Unread badge */}
            {!isOpen && unreadCount > 0 && (
                <span
                    className="absolute -top-1 -right-1 flex h-5 min-w-5 animate-pulse items-center justify-center rounded-full bg-[var(--arabut-gold-bright)] px-1 text-xs font-black text-[var(--arabut-navy-deep)] shadow-lg motion-reduce:animate-none"
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
