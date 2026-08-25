import type { CoinsQuantityTier } from '@/types/coins';

/**
 * The quantities a customer may buy, and how far the slider moves.
 *
 * The step widens as the quantity climbs: a jump fine enough at fifty thousand
 * coins leaves the slider crawling at three million. Because position no longer
 * implies quantity, the slider runs over an index into this list rather than
 * over the quantity itself.
 *
 * This mirrors CoinsQuantityRules on the server, which stays authoritative — a
 * quantity that slips past here is still refused when the cart is priced.
 */
export function legalQuantities(
    minimum: number,
    tiers: CoinsQuantityTier[],
    maximum: number,
): number[] {
    const quantities: number[] = [];
    let current = minimum;

    if (current <= maximum) {
        quantities.push(current);
    }

    for (const tier of tiers) {
        while (current < tier.upTo) {
            current += tier.step;

            if (current > maximum) {
                return quantities;
            }

            quantities.push(current);
        }
    }

    return quantities;
}

export function acceptsQuantity(
    quantity: number,
    minimum: number,
    tiers: CoinsQuantityTier[],
    maximum: number,
): boolean {
    if (
        !Number.isInteger(quantity) ||
        quantity < minimum ||
        quantity > maximum
    ) {
        return false;
    }

    let floor = minimum;

    for (const tier of tiers) {
        if (quantity <= tier.upTo) {
            return (quantity - floor) % tier.step === 0;
        }

        floor = tier.upTo;
    }

    return false;
}

/** The nearest buyable quantity at or below a typed value, for snapping. */
export function nearestQuantity(
    quantity: number,
    minimum: number,
    tiers: CoinsQuantityTier[],
    maximum: number,
): number {
    const quantities = legalQuantities(minimum, tiers, maximum);
    let nearest = quantities[0] ?? minimum;

    for (const candidate of quantities) {
        // `<=` so a value exactly between two steps rounds up, matching what a
        // customer sees the slider do.
        if (Math.abs(candidate - quantity) <= Math.abs(nearest - quantity)) {
            nearest = candidate;
        }
    }

    return nearest;
}
