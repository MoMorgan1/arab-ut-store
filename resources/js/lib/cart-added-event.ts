import { flyToCart } from '@/lib/fly-to-cart';

export const CART_ADDED_EVENT = 'arabut:cart-added';

/**
 * What the analytics module reports for an add-to-cart. Money is the SAR
 * checkout amount in halalah; emitters that only hold a display-currency
 * price leave `priceMinorSar` out rather than send a wrong number.
 */
export type CartAddedAnalytics = {
    id: string;
    name: string;
    priceMinorSar?: number;
    quantity: number;
    serviceType: string;
};

export type CartAddedDetail = {
    analytics?: CartAddedAnalytics;
    /** Running item count and SAR total shown under the sheet title. */
    cartCount?: number;
    cartTotalHalalah?: number;
    cartUrl: string;
    from?: HTMLElement;
    imageAlt: string;
    imageUrl: string;
    itemLabel: string;
    /** The formatted line price the emitter already displays, if it has one. */
    priceLabel?: string;
    /** SBC art is larger and rises above the item tile. */
    raisedArt?: boolean;
    selectionLabel?: string;
    variant?: 'added' | 'duplicate';
};

export type CartDuplicateDetail = {
    cartUrl: string;
    imageAlt: string;
    imageUrl: string;
    itemLabel: string;
    priceLabel?: string;
    raisedArt?: boolean;
    selectionLabel?: string;
};

/**
 * Announces a successful add. When `from` is present the flight runs first
 * and the sheet event is dispatched when the flight resolves, so the sheet
 * lands at the same moment as the header badge bump — never before the chip
 * takes off. Without `from` the event is dispatched synchronously in the
 * same call (analytics asserts the dataLayer right after an un-awaited call).
 */
export function announceCartAddition(detail: CartAddedDetail): Promise<void> {
    const { from, ...notice } = detail;

    if (from === undefined) {
        window.dispatchEvent(
            new CustomEvent<CartAddedDetail>(CART_ADDED_EVENT, {
                detail: { ...notice, variant: 'added' },
            }),
        );

        return Promise.resolve();
    }

    return flyToCart({
        from,
        imageUrl: detail.imageUrl,
        imageAlt: detail.imageAlt,
    }).then(() => {
        window.dispatchEvent(
            new CustomEvent<CartAddedDetail>(CART_ADDED_EVENT, {
                detail: { ...notice, variant: 'added' },
            }),
        );
    });
}

/** Same sheet in its amber duplicate state; no flight, dispatched at once. */
export function announceCartDuplicate(detail: CartDuplicateDetail): void {
    window.dispatchEvent(
        new CustomEvent<CartAddedDetail>(CART_ADDED_EVENT, {
            detail: { ...detail, variant: 'duplicate' },
        }),
    );
}
