import { Link } from '@inertiajs/react';
import { ArrowLeft, ArrowRight } from 'lucide-react';

import type { ChatServiceCard } from '@/lib/chat-cards';

/**
 * Clickable service cards under an assistant reply.
 *
 * They carry no price: prices are live data and belong on the product page, so
 * the card invites the customer there instead of quoting a number in chat.
 * Inertia navigation keeps the conversation alive across the page change, and
 * on phones the sheet steps out of the way so the customer actually sees the
 * page they just opened.
 */
export function ChatServiceCards({
    cards,
    locale,
    onNavigate,
}: {
    cards: ChatServiceCard[];
    locale: string;
    onNavigate?: () => void;
}) {
    if (cards.length === 0) {
        return null;
    }

    const isEn = locale === 'en';
    const Arrow = isEn ? ArrowRight : ArrowLeft;

    return (
        <div className="mt-2 flex flex-col gap-2">
            {cards.map((card, index) => (
                <Link
                    key={card.id}
                    href={card.url}
                    prefetch
                    onClick={onNavigate}
                    data-testid="chat-service-card"
                    className="chat-service-card chat-lift chat-press group flex flex-col gap-2.5 rounded-2xl border border-[var(--chat-line-strong)] bg-[var(--chat-card)] p-3 text-start shadow-[0_2px_10px_rgb(13_11_8/0.06)] transition-[transform,box-shadow,border-color] focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--arabut-focus)]"
                    style={{ animationDelay: `${index * 70}ms` }}
                >
                    <span className="flex items-center gap-3">
                        <img
                            src={card.image}
                            width="112"
                            height="112"
                            alt=""
                            aria-hidden="true"
                            loading="lazy"
                            className="h-14 w-14 flex-shrink-0 rounded-xl bg-[var(--chat-tint)] object-contain p-1"
                        />

                        <span className="flex min-w-0 flex-col gap-0.5">
                            <span className="text-[15px] leading-snug font-semibold text-[var(--chat-ink)]">
                                {card.title}
                            </span>
                            <span className="text-xs leading-snug text-[var(--chat-muted)]">
                                {card.subtitle}
                            </span>
                        </span>
                    </span>

                    <span className="flex items-center justify-center gap-1 rounded-xl bg-[var(--chat-tint)] px-3 py-2 text-[13px] font-semibold text-[var(--chat-accent-ink)]">
                        {card.cta}
                        <Arrow
                            aria-hidden="true"
                            className="h-3.5 w-3.5 transition-transform duration-200 group-hover:translate-x-0.5 rtl:group-hover:-translate-x-0.5"
                        />
                    </span>
                </Link>
            ))}
        </div>
    );
}
