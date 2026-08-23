import { Link } from '@inertiajs/react';
import { ArrowLeft, ArrowRight } from 'lucide-react';

import type { ChatServiceCard } from '@/lib/chat-cards';

/**
 * Clickable service cards under an assistant reply.
 *
 * They carry no price: prices are live data and belong on the product page, so
 * the card invites the customer there instead of quoting a number in chat.
 * Inertia navigation keeps the conversation alive across the page change.
 */
export function ChatServiceCards({
    cards,
    locale,
}: {
    cards: ChatServiceCard[];
    locale: string;
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
                    data-testid="chat-service-card"
                    className="chat-service-card chat-lift chat-press group flex items-center gap-3 rounded-2xl border border-[var(--chat-line-strong)] bg-[var(--chat-card)] px-3.5 py-3 text-start shadow-[0_2px_10px_rgb(13_11_8/0.06)] transition-[transform,box-shadow,border-color] focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--arabut-focus)]"
                    style={{ animationDelay: `${index * 70}ms` }}
                >
                    <img
                        src="/images/arabut-logo-header.webp"
                        width="36"
                        height="36"
                        alt=""
                        aria-hidden="true"
                        className="h-9 w-9 flex-shrink-0 rounded-xl object-contain"
                    />

                    <span className="flex min-w-0 flex-col">
                        <span className="truncate text-[14px] font-semibold text-[var(--chat-ink)]">
                            {card.title}
                        </span>
                        <span className="truncate text-xs text-[var(--chat-muted)]">
                            {card.subtitle}
                        </span>
                    </span>

                    <span className="ms-auto flex flex-shrink-0 items-center gap-1 rounded-full bg-[var(--chat-tint)] px-2.5 py-1 text-[11px] font-semibold text-[var(--chat-accent-ink)]">
                        {card.cta}
                        <Arrow
                            aria-hidden="true"
                            className="h-3 w-3 transition-transform duration-200 group-hover:translate-x-0.5 rtl:group-hover:-translate-x-0.5"
                        />
                    </span>
                </Link>
            ))}
        </div>
    );
}
