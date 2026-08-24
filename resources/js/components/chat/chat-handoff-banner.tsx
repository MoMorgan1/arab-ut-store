import { Check, Clock } from 'lucide-react';
import type React from 'react';
import type { ChatConversationTicket, ChatHandoffState } from '@/types/chat';

export type ChatHandoffBannerProps = {
    handoffState: ChatHandoffState;
    ticket?: ChatConversationTicket | null;
    locale?: string;
    disabled?: boolean;
    onRequestNewTicket?: () => void;
};

export const ChatHandoffBanner: React.FC<ChatHandoffBannerProps> = ({
    handoffState,
    ticket,
    locale = 'ar',
    disabled = false,
    onRequestNewTicket,
}) => {
    if (handoffState === 'none' || handoffState === 'offered' || !ticket) {
        return null;
    }

    const isEn = locale === 'en';
    const dir = isEn ? 'ltr' : 'rtl';
    const ticketNumber = ticket.number || '';
    const responderName = ticket.responderName?.trim();

    if (handoffState === 'resolved') {
        const title = isEn ? 'Ticket resolved' : 'تم حل التذكرة';
        const reopenLabel = isEn ? 'Still need help?' : 'تحتاج مساعدة أكثر؟';

        return (
            <div
                dir={dir}
                data-testid="chat-handoff-banner"
                data-handoff-state="resolved"
                className="chat-drop-in flex items-center justify-between gap-3 border-b border-[var(--chat-line)] bg-white px-4 py-2.5 text-start text-[var(--chat-ink)] shadow-xs"
            >
                <div className="flex min-w-0 items-center gap-2.5">
                    <div
                        aria-hidden="true"
                        className="flex h-[26px] w-[26px] flex-shrink-0 items-center justify-center rounded-full border border-[#22a06b]/40 bg-[#22a06b]/12 text-[#22a06b]"
                    >
                        <Check className="h-3.5 w-3.5 stroke-[2.5]" />
                    </div>
                    <div className="flex min-w-0 flex-col">
                        <span className="truncate text-[13.5px] font-bold text-[var(--chat-ink)]">
                            {title}
                        </span>
                        {ticketNumber && (
                            <span className="truncate text-xs text-[var(--chat-muted)]">
                                {ticketNumber}
                            </span>
                        )}
                    </div>
                </div>

                {onRequestNewTicket && (
                    <button
                        type="button"
                        onClick={onRequestNewTicket}
                        disabled={disabled}
                        className="chat-press flex min-h-[44px] min-w-[44px] flex-shrink-0 items-center justify-center rounded-full border border-[#d4a843] bg-[#f3ead6]/60 px-3.5 py-1.5 text-xs font-bold text-[#8a7243] transition-colors hover:bg-[#f3ead6] hover:text-[#5a4823] focus-visible:outline focus-visible:outline-2 focus-visible:outline-[var(--arabut-focus)] disabled:cursor-not-allowed disabled:opacity-50"
                    >
                        {reopenLabel}
                    </button>
                )}
            </div>
        );
    }

    if (handoffState === 'active') {
        const title = responderName
            ? isEn
                ? `${responderName} from the team is replying`
                : `${responderName} من الفريق يرد عليك`
            : isEn
              ? 'The team is replying'
              : 'الفريق يرد عليك';

        // No responder name means the banner is speaking for the team, not for
        // a person, so the avatar carries the store's initial rather than a
        // hardcoded 'M' — which was Mohamed's initial standing in for everyone.
        const initial = responderName
            ? responderName.charAt(0).toUpperCase()
            : isEn
              ? 'A'
              : 'ع';

        return (
            <div
                dir={dir}
                data-testid="chat-handoff-banner"
                data-handoff-state="active"
                className="chat-drop-in flex items-center justify-between gap-3 border-b border-[#d4a843]/30 bg-[#f3ead6] px-4 py-2.5 text-start text-[var(--chat-ink)] shadow-xs"
            >
                <div className="flex min-w-0 items-center gap-2.5">
                    <div
                        aria-hidden="true"
                        className="flex h-[26px] w-[26px] flex-shrink-0 items-center justify-center rounded-full bg-[#d4a843] text-xs font-bold text-white shadow-xs"
                    >
                        {initial}
                    </div>
                    <div className="flex min-w-0 flex-col">
                        <span className="truncate text-[13.5px] font-bold text-[#1a1714]">
                            {title}
                        </span>
                        {ticketNumber && (
                            <span className="truncate text-xs font-medium text-[#8a7243]">
                                {ticketNumber}
                            </span>
                        )}
                    </div>
                </div>
            </div>
        );
    }

    // Requested state
    const requestedTitle = isEn
        ? 'Your request reached the team'
        : 'طلبك وصل للفريق';

    return (
        <div
            dir={dir}
            data-testid="chat-handoff-banner"
            data-handoff-state="requested"
            className="chat-drop-in flex items-center justify-between gap-3 border-b border-[#d4a843]/30 bg-[#f3ead6] px-4 py-2.5 text-start text-[var(--chat-ink)] shadow-xs"
        >
            <div className="flex min-w-0 items-center gap-2.5">
                <div
                    aria-hidden="true"
                    className="flex h-[26px] w-[26px] flex-shrink-0 items-center justify-center rounded-full bg-white text-[#d4a843] shadow-xs"
                >
                    <Clock className="h-3.5 w-3.5 stroke-[2.5]" />
                </div>
                <div className="flex min-w-0 flex-col">
                    <span className="truncate text-[13.5px] font-bold text-[#1a1714]">
                        {requestedTitle}
                    </span>
                    {ticketNumber && (
                        <span className="truncate text-xs font-medium text-[#8a7243]">
                            {ticketNumber}
                        </span>
                    )}
                </div>
            </div>
        </div>
    );
};
