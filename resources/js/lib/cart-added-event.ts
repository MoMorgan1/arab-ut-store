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
    imageAlt: string;
    imageUrl: string;
    itemLabel: string;
    selectionLabel?: string;
};

export function announceCartAddition(detail: CartAddedDetail) {
    window.dispatchEvent(
        new CustomEvent<CartAddedDetail>(CART_ADDED_EVENT, { detail }),
    );
}
