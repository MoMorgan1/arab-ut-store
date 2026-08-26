import type { CoinsQuantityTier } from '@/types/coins';

/**
 * Two different things live here, and keeping them apart is the point.
 *
 * The rounding unit is what a customer may buy: any multiple of it between the
 * floor and the ceiling. Typing an exact amount is the normal way to order, so
 * the buyable set is deliberately dense.
 *
 * The bands are only where the slider stops. A step fine enough at fifty
 * thousand coins leaves the slider crawling at three million, so the slider
 * steps in bands while the customer stays free to type anything on the unit.
 * Because position no longer implies quantity, the slider runs over an index
 * into the stop list rather than over the quantity itself.
 *
 * This mirrors CoinsQuantityRules on the server, which stays authoritative — a
 * quantity that slips past here is still refused when the cart is priced.
 */
export function sliderStops(
    minimum: number,
    tiers: CoinsQuantityTier[],
    maximum: number,
): number[] {
    const stops: number[] = [];
    let current = minimum;

    if (current <= maximum) {
        stops.push(current);
    }

    for (const tier of tiers) {
        while (current < tier.upTo) {
            current += tier.step;

            if (current > maximum) {
                return stops;
            }

            stops.push(current);
        }
    }

    return stops;
}

export function acceptsQuantity(
    quantity: number,
    minimum: number,
    maximum: number,
    roundingUnit: number,
): boolean {
    return (
        Number.isInteger(quantity) &&
        Number.isInteger(roundingUnit) &&
        roundingUnit > 0 &&
        quantity >= minimum &&
        quantity <= maximum &&
        quantity % roundingUnit === 0
    );
}

/**
 * The buyable quantity closest to what the customer typed.
 *
 * A value exactly between two units rounds up, so the number never drops below
 * what someone deliberately asked for by more than they gain.
 */
export function roundQuantity(
    quantity: number,
    minimum: number,
    maximum: number,
    roundingUnit: number,
): number {
    const clamped = Math.min(maximum, Math.max(minimum, quantity));
    const rounded = Math.floor(clamped / roundingUnit + 0.5) * roundingUnit;

    return Math.min(maximum, Math.max(minimum, rounded));
}

/**
 * Where to park the slider thumb for a quantity that may sit between stops.
 *
 * A typed amount need not be a stop, and a thumb that snapped to zero would
 * tell the customer their order had changed when it had not.
 */
export function nearestStopIndex(quantity: number, stops: number[]): number {
    if (stops.length === 0) {
        return 0;
    }

    let nearest = 0;

    for (let index = 0; index < stops.length; index++) {
        // `<=` so a value exactly between two stops takes the higher one,
        // matching the way a typed amount rounds.
        if (
            Math.abs(stops[index] - quantity) <=
            Math.abs(stops[nearest] - quantity)
        ) {
            nearest = index;
        }
    }

    return nearest;
}
