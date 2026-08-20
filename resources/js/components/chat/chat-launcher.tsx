import { MessageSquare, Sparkles, X } from 'lucide-react';
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
            className="group relative isolate flex h-14 w-14 animate-in cursor-pointer items-center justify-center overflow-visible rounded-full border border-white/30 bg-[linear-gradient(145deg,color-mix(in_srgb,var(--arabut-gold-bright)_84%,transparent),color-mix(in_srgb,var(--arabut-gold)_72%,transparent))] text-[var(--arabut-navy-deep)] shadow-[0_12px_34px_rgba(184,137,46,0.38),0_3px_10px_rgba(0,0,0,0.34)] backdrop-blur-xl transition-[transform,box-shadow,filter] duration-200 [transition-timing-function:cubic-bezier(0.25,1,0.5,1)] zoom-in-90 [animation-duration:300ms] [animation-timing-function:cubic-bezier(0.16,1,0.3,1)] fade-in hover:-translate-y-0.5 hover:shadow-[0_18px_42px_rgba(184,137,46,0.48),0_5px_14px_rgba(0,0,0,0.38)] hover:brightness-105 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-[var(--arabut-focus)] active:translate-y-0 active:scale-[0.96] motion-reduce:transform-none motion-reduce:animate-none motion-reduce:transition-none sm:h-[62px] sm:w-[62px]"
        >
            <span className="sr-only">{label}</span>

            {/* Warm liquid-glass depth; decorative layers never intercept input. */}
            <span
                className="pointer-events-none absolute -inset-1.5 -z-10 rounded-full bg-[var(--arabut-gold)]/28 blur-md transition-opacity duration-200 group-hover:opacity-90 motion-reduce:hidden"
                aria-hidden="true"
            />
            <span
                className="pointer-events-none absolute inset-[1px] overflow-hidden rounded-full shadow-[inset_0_1px_0_rgba(255,255,255,0.5),inset_0_-10px_24px_rgba(61,42,6,0.18)]"
                aria-hidden="true"
            >
                <span className="absolute inset-x-2 top-1 h-[44%] rounded-full bg-white/35 blur-[1px]" />
                <span className="absolute inset-x-3 bottom-1.5 h-[18%] rounded-full bg-[var(--arabut-navy-deep)]/12 blur-sm" />
            </span>

            <span
                className="relative z-10 grid h-8 w-8 place-items-center"
                aria-hidden="true"
            >
                <span
                    className={`absolute h-7 w-7 transition-[opacity,transform] duration-150 motion-reduce:transition-none ${
                        isOpen
                            ? 'scale-75 rotate-45 opacity-0'
                            : 'scale-100 rotate-0 opacity-100'
                    }`}
                >
                    <MessageSquare className="h-7 w-7 stroke-[2.2]" />
                </span>
                <span
                    className={`absolute -end-0.5 -top-0.5 h-3.5 w-3.5 transition-[opacity,transform] duration-150 motion-reduce:transition-none ${
                        isOpen ? 'scale-50 opacity-0' : 'scale-100 opacity-100'
                    }`}
                >
                    <Sparkles className="h-3.5 w-3.5 stroke-[2.4]" />
                </span>
                <span
                    className={`absolute h-7 w-7 transition-[opacity,transform] duration-150 motion-reduce:transition-none ${
                        isOpen
                            ? 'scale-100 rotate-0 opacity-100'
                            : 'scale-75 -rotate-45 opacity-0'
                    }`}
                >
                    <X className="h-7 w-7 stroke-[2.2]" />
                </span>
            </span>

            {/* Unread badge */}
            {!isOpen && unreadCount > 0 && (
                <span
                    className="absolute -top-1 -right-1 flex h-5 min-w-5 animate-[pulse_1.5s_ease-in-out_3] items-center justify-center rounded-full border border-[var(--arabut-gold-bright)] bg-[var(--arabut-navy-deep)] px-1 text-xs font-black text-[var(--arabut-gold-bright)] shadow-lg motion-reduce:animate-none"
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
