export const CART_ADDED_EVENT = 'arabut:cart-added';

export type CartAddedDetail = {
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
