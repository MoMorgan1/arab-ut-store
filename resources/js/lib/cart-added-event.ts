export const CART_ADDED_EVENT = 'arabut:cart-added';

export type CartAddedDetail = {
    cartUrl: string;
    itemLabel: string;
};

export function announceCartAddition(detail: CartAddedDetail) {
    window.dispatchEvent(
        new CustomEvent<CartAddedDetail>(CART_ADDED_EVENT, { detail }),
    );
}
