import {
    ChevronDown,
    ChevronLeft,
    ChevronRight,
    Info,
    LayoutGrid,
    ListOrdered,
    Send,
    Sparkles,
    Truck,
    X,
} from 'lucide-react';
import type React from 'react';
import { chatTopicsFor } from '@/lib/chat-topics';
import type { ChatTopicId } from '@/lib/chat-topics';

export type ChatHomeLastMessage = {
    preview: string;
    createdAt: string;
};

export type ChatHomeProps = {
    locale?: string;
    hasConversation: boolean;
    lastMessage: ChatHomeLastMessage | null;
    disabled?: boolean;
    isMobileDialog?: boolean;
    closeButtonRef?: React.Ref<HTMLButtonElement>;
    onStart: () => void;
    onContinue: () => void;
    onSelectTopic: (label: string) => void;
    onClose: () => void;
};

const TOPIC_ICONS: Record<
    ChatTopicId,
    React.ComponentType<{ className?: string }>
> = {
    prices: ListOrdered,
    services: LayoutGrid,
    'track-order': Truck,
    support: Info,
};

const CARD_BASE =
    'chat-lift rounded-2xl border border-[var(--chat-line)] bg-[var(--chat-card)] text-start text-[var(--chat-ink)] shadow-[0_6px_18px_rgb(13_11_8/0.06)] focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--arabut-focus)] disabled:cursor-not-allowed disabled:opacity-50 motion-reduce:transform-none';

function relativeTime(iso: string, isEn: boolean): string {
    const minutes = Math.max(
        0,
        Math.round((Date.now() - new Date(iso).getTime()) / 60000),
    );

    if (minutes < 1) {
        return isEn ? 'Just now' : 'الآن';
    }

    if (minutes < 60) {
        return isEn ? `${minutes} min ago` : `قبل ${minutes} د`;
    }

    const hours = Math.round(minutes / 60);

    if (hours < 24) {
        return isEn ? `${hours} h ago` : `قبل ${hours} س`;
    }

    return new Date(iso).toLocaleDateString(isEn ? 'en-US' : 'ar-SA', {
        month: 'short',
        day: 'numeric',
    });
}

export const ChatHome: React.FC<ChatHomeProps> = ({
    locale = 'ar',
    hasConversation,
    lastMessage,
    disabled = false,
    isMobileDialog = false,
    closeButtonRef,
    onStart,
    onContinue,
    onSelectTopic,
    onClose,
}) => {
    const isEn = locale === 'en';
    const dir = isEn ? 'ltr' : 'rtl';
    const Chevron = isEn ? ChevronRight : ChevronLeft;
    const topics = chatTopicsFor(locale);

    const copy = {
        greeting: isEn ? 'Hi there' : 'أهلًا بك',
        subgreeting: isEn
            ? 'How can we help you today?'
            : 'كيف نقدر نساعدك اليوم؟',
        startTitle: isEn ? 'Send us a message' : 'أرسل لنا رسالة',
        startSubtitle: isEn ? 'Usually replies instantly' : 'عادة نرد فورًا',
        startCta: isEn ? 'Start a conversation' : 'ابدأ محادثة',
        continueTitle: isEn ? 'Continue your conversation' : 'متابعة المحادثة',
        topics: isEn ? 'Popular topics' : 'مواضيع شائعة',
        close: isEn ? 'Close chat' : 'إغلاق الشات',
    };

    let cardIndex = 0;

    return (
        <div
            dir={dir}
            className="flex h-full min-h-0 flex-col bg-[var(--chat-surface)] text-[var(--chat-ink)]"
        >
            {/* Hero */}
            <div className="flex flex-col gap-5 bg-[var(--chat-hero)] px-6 pt-6 pb-7 text-[var(--chat-hero-ink)]">
                <div className="flex items-center justify-between">
                    <div className="chat-pop-in flex items-center gap-2.5">
                        <img
                            src="/images/arabut-logo-header.webp"
                            width="36"
                            height="36"
                            alt=""
                            aria-hidden="true"
                            className="h-9 w-9 rounded-xl object-contain"
                        />
                        <span className="text-[13px] font-semibold tracking-wide text-[var(--arabut-muted)]">
                            Arab UT
                        </span>
                    </div>
                    <button
                        ref={closeButtonRef}
                        type="button"
                        onClick={onClose}
                        aria-label={copy.close}
                        className="chat-press flex h-11 w-11 items-center justify-center rounded-xl text-[var(--arabut-muted)] transition-colors hover:bg-white/10 hover:text-[var(--chat-hero-ink)] focus-visible:outline focus-visible:outline-2 focus-visible:outline-[var(--arabut-focus)]"
                    >
                        {isMobileDialog ? (
                            <ChevronDown
                                aria-hidden="true"
                                className="h-5 w-5"
                            />
                        ) : (
                            <X aria-hidden="true" className="h-5 w-5" />
                        )}
                    </button>
                </div>
                <div className="flex flex-col gap-1.5">
                    <h2
                        className="chat-stagger-in text-[28px] leading-tight font-bold text-[#fbf8f2]"
                        style={{ ['--i' as string]: 0 }}
                    >
                        {copy.greeting}
                    </h2>
                    <p
                        className="chat-stagger-in text-[17px] leading-snug text-[var(--arabut-muted)]"
                        style={{ ['--i' as string]: 1 }}
                    >
                        {copy.subgreeting}
                    </p>
                </div>
            </div>

            {/* Body */}
            <div className="-mt-3.5 flex min-h-0 flex-1 flex-col gap-3 overflow-y-auto px-4 pb-4">
                {hasConversation && (
                    <button
                        type="button"
                        onClick={onContinue}
                        disabled={disabled}
                        className={`chat-stagger-in ${CARD_BASE} flex items-center gap-3 px-4 py-3.5`}
                        style={{ ['--i' as string]: cardIndex++ }}
                    >
                        <span
                            aria-hidden="true"
                            className="flex h-10 w-10 flex-shrink-0 items-center justify-center rounded-full bg-[var(--chat-tint)] text-[var(--chat-accent-ink)]"
                        >
                            <Sparkles className="h-5 w-5" />
                        </span>
                        <span className="flex min-w-0 flex-1 flex-col gap-0.5">
                            <span className="flex items-baseline justify-between gap-2">
                                <span className="text-sm font-semibold">
                                    {copy.continueTitle}
                                </span>
                                {lastMessage && (
                                    <span className="text-xs text-[var(--chat-faint)]">
                                        {relativeTime(
                                            lastMessage.createdAt,
                                            isEn,
                                        )}
                                    </span>
                                )}
                            </span>
                            {lastMessage && (
                                <span
                                    dir="auto"
                                    className="truncate text-[13px] text-[var(--chat-muted)]"
                                >
                                    {lastMessage.preview}
                                </span>
                            )}
                        </span>
                        <Chevron
                            aria-hidden="true"
                            className="h-5 w-5 flex-shrink-0 text-[var(--chat-faint)]"
                        />
                    </button>
                )}

                <div
                    className={`chat-stagger-in ${CARD_BASE} flex items-center justify-between gap-3 p-4 hover:translate-y-0 hover:shadow-[0_6px_18px_rgb(13_11_8/0.06)]`}
                    style={{ ['--i' as string]: cardIndex++ }}
                >
                    <div className="flex flex-col gap-0.5">
                        <span className="text-[15px] font-semibold">
                            {copy.startTitle}
                        </span>
                        <span className="text-[13px] text-[var(--chat-muted)]">
                            {copy.startSubtitle}
                        </span>
                    </div>
                    <button
                        type="button"
                        onClick={onStart}
                        disabled={disabled}
                        className="chat-press flex h-11 flex-shrink-0 items-center gap-2 rounded-xl bg-[var(--chat-accent)] px-4 text-sm font-bold text-[var(--chat-hero)] transition-opacity hover:opacity-90 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--arabut-focus)] disabled:cursor-not-allowed disabled:opacity-50"
                    >
                        <span>{copy.startCta}</span>
                        <Send
                            aria-hidden="true"
                            className="h-4 w-4 rtl:-scale-x-100"
                        />
                    </button>
                </div>

                <div className="flex flex-col gap-2 px-1 pt-1">
                    <span className="text-xs font-semibold tracking-wide text-[var(--chat-faint)]">
                        {copy.topics}
                    </span>
                    <div className="grid grid-cols-2 gap-2">
                        {topics.map((topic) => {
                            const Icon = TOPIC_ICONS[topic.id];

                            return (
                                <button
                                    key={topic.id}
                                    type="button"
                                    onClick={() => onSelectTopic(topic.label)}
                                    disabled={disabled}
                                    className={`chat-stagger-in ${CARD_BASE} flex min-h-[52px] items-center gap-2.5 rounded-[14px] px-3.5 py-3 text-sm font-medium shadow-none`}
                                    style={{ ['--i' as string]: cardIndex++ }}
                                >
                                    <Icon
                                        aria-hidden="true"
                                        className="h-5 w-5 flex-shrink-0 text-[var(--chat-accent-ink)]"
                                    />
                                    <span>{topic.label}</span>
                                </button>
                            );
                        })}
                    </div>
                </div>
            </div>
        </div>
    );
};
