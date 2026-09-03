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
    cartUrl: string;
    from?: HTMLElement;
    imageAlt: string;
    imageUrl: string;
    itemLabel: string;
    selectionLabel?: string;
};

export function announceCartAddition(detail: CartAddedDetail): Promise<void> {
    const { from, ...notice } = detail;

    window.dispatchEvent(
        new CustomEvent<CartAddedDetail>(CART_ADDED_EVENT, {
            detail: notice,
        }),
    );

    if (from === undefined) {
        return Promise.resolve();
    }

    return flyToCart({
        from,
        imageUrl: detail.imageUrl,
        imageAlt: detail.imageAlt,
    });
}
