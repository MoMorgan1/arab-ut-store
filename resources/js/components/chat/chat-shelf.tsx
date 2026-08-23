import { Link } from '@inertiajs/react';

import type { ChatShelfItem } from '@/lib/chat-shelf';
import { formatMinorUnits } from '@/lib/money';
import type { ChatServicePrices } from '@/types/chat';

/**
 * A swipeable shelf of real products under an assistant reply.
 *
 * SBC has no single price, so the reply offers a few actual challenges and the
 * customer picks one instead of being quoted a number that is wrong for every
 * other challenge. Prices are looked up live by product id — never stored in
 * the message, because chat history is permanent.
 */
export function ChatShelf({
    items,
    servicePrices = {},
    locale,
    onNavigate,
}: {
    items: ChatShelfItem[];
    servicePrices?: ChatServicePrices;
    locale: string;
    onNavigate?: () => void;
}) {
    if (items.length === 0) {
        return null;
    }

    const isEn = locale === 'en';
    const moneyLocale: 'ar' | 'en' = isEn ? 'en' : 'ar';

    return (
        <div className="mt-2" data-testid="chat-shelf">
            <div
                // The shelf scrolls inside itself; the transcript must never
                // scroll sideways with it.
                className="chat-shelf-track -mx-1 flex snap-x snap-mandatory gap-2 overflow-x-auto px-1 pb-1"
                role="list"
            >
                {items.map((item, index) => {
                    const price = servicePrices[`sbc:${item.id}`];
                    let priceLabel: string | null = null;

                    if (
                        price &&
                        typeof price.amountMinor === 'number' &&
                        typeof price.currency === 'string'
                    ) {
                        try {
                            const formatted = formatMinorUnits(
                                price.amountMinor,
                                price.currency,
                                moneyLocale,
                            );
                            priceLabel = isEn
                                ? `From ${formatted}`
                                : `يبدأ من ${formatted}`;
                        } catch {
                            priceLabel = null;
                        }
                    }

                    return (
                        <Link
                            key={item.id}
                            href={item.url}
                            prefetch
                            onClick={onNavigate}
                            role="listitem"
                            data-testid="chat-shelf-card"
                            className="chat-service-card chat-lift chat-press flex w-[148px] flex-shrink-0 snap-start flex-col gap-2 rounded-2xl border border-[var(--chat-line-strong)] bg-[var(--chat-card)] p-2.5 text-start shadow-[0_2px_10px_rgb(13_11_8/0.06)] transition-[transform,box-shadow,border-color] focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--arabut-focus)]"
                            style={{ animationDelay: `${index * 70}ms` }}
                        >
                            <img
                                src={item.image}
                                width="256"
                                height="256"
                                alt=""
                                aria-hidden="true"
                                loading="lazy"
                                className="h-20 w-full rounded-xl bg-[var(--chat-tint)] object-contain p-1"
                            />

                            <span className="line-clamp-2 text-[13px] leading-snug font-semibold text-[var(--chat-ink)]">
                                {item.title}
                            </span>

                            {priceLabel !== null && (
                                <span
                                    data-testid="chat-shelf-price"
                                    className="text-xs font-semibold text-[var(--chat-accent-ink)]"
                                >
                                    {priceLabel}
                                </span>
                            )}
                        </Link>
                    );
                })}
            </div>
        </div>
    );
}
