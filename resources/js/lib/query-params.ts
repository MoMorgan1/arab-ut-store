import type { CoinsStep } from '@/components/configurator/coins/progress-rail';
import type {
    CoinsAmountRules,
    CoinsDeliveryValue,
    CoinsPlatformOption,
    CoinsPlatformValue,
} from '@/types/coins';
import type { Division } from '@/types/manual-services';

/**
 * Safely extracts query string from a URL string or search string.
 * Strips leading '?' or path/origin prefix if a full URL/path was passed.
 */
export function getQueryString(search?: unknown): string {
    if (typeof search !== 'string' || search.trim() === '') {
        return '';
    }

    const questionIndex = search.indexOf('?');

    if (questionIndex !== -1) {
        const hashIndex = search.indexOf('#', questionIndex);

        return hashIndex !== -1
            ? search.slice(questionIndex + 1, hashIndex)
            : search.slice(questionIndex + 1);
    }

    const hashIndex = search.indexOf('#');

    return hashIndex !== -1 ? search.slice(0, hashIndex) : search;
}

/**
 * Safely parses a search string into a URLSearchParams object.
 * Never throws on hostile, malformed, or non-string inputs.
 */
export function parseQueryParams(search?: unknown): URLSearchParams {
    try {
        const query = getQueryString(search);

        return new URLSearchParams(query);
    } catch {
        return new URLSearchParams();
    }
}

/**
 * Reads a named query parameter and validates it against an allowed set of string values.
 * Returns `undefined` if missing, empty, malformed, or not in the allowed set.
 * Never throws on hostile input (unicode, __proto__, arrays, objects, etc.).
 */
export function readQueryParam<T extends string>(
    search: unknown,
    name: unknown,
    allowedValues: readonly T[] | ReadonlySet<T>,
): T | undefined {
    if (typeof name !== 'string' || name === '') {
        return undefined;
    }

    try {
        const params = parseQueryParams(search);

        if (!params.has(name)) {
            return undefined;
        }

        const value = params.get(name);

        if (value === null || value === '') {
            return undefined;
        }

        const isAllowed = Array.isArray(allowedValues)
            ? (allowedValues as readonly string[]).includes(value)
            : (allowedValues as ReadonlySet<string>).has(value);

        return isAllowed ? (value as T) : undefined;
    } catch {
        return undefined;
    }
}

/**
 * Reads a named query parameter as an integer and optionally validates against an allowed list.
 * Returns `undefined` if missing, empty, not a valid safe integer, or out of set.
 */
export function readNumericQueryParam(
    search: unknown,
    name: unknown,
    allowedValues?: readonly number[] | ReadonlySet<number>,
): number | undefined {
    if (typeof name !== 'string' || name === '') {
        return undefined;
    }

    try {
        const params = parseQueryParams(search);

        if (!params.has(name)) {
            return undefined;
        }

        const raw = params.get(name);

        if (raw === null || raw.trim() === '') {
            return undefined;
        }

        if (!/^-?\d+$/.test(raw.trim())) {
            return undefined;
        }

        const value = Number(raw.trim());

        if (!Number.isSafeInteger(value)) {
            return undefined;
        }

        if (allowedValues !== undefined) {
            const isAllowed = Array.isArray(allowedValues)
                ? (allowedValues as readonly number[]).includes(value)
                : (allowedValues as ReadonlySet<number>).has(value);

            if (!isAllowed) {
                return undefined;
            }
        }

        return value;
    } catch {
        return undefined;
    }
}

/**
 * Reads a named boolean query parameter.
 * Returns:
 * - `true` for 'true' or '1'
 * - `false` for 'false' or '0'
 * - `undefined` for missing, empty, or other values.
 */
export function readBooleanQueryParam(
    search: unknown,
    name: unknown,
): boolean | undefined {
    if (typeof name !== 'string' || name === '') {
        return undefined;
    }

    try {
        const params = parseQueryParams(search);

        if (!params.has(name)) {
            return undefined;
        }

        const raw = params.get(name);

        if (raw === null || raw === '') {
            return undefined;
        }

        const normalized = raw.trim().toLowerCase();

        if (normalized === 'true' || normalized === '1') {
            return true;
        }

        if (normalized === 'false' || normalized === '0') {
            return false;
        }

        return undefined;
    } catch {
        return undefined;
    }
}

/**
 * Computes the initial Rivals division route from the URL search string.
 * If both currentDivision and targetDivision form a valid route on the ladder, returns them.
 * If either is missing, invalid, out-of-ladder, or reverse, ignores both and returns default.
 */
export function getInitialRivalsRoute(
    search: unknown,
    ladder: readonly Division[],
    defaultFrom: Division = '5',
    defaultTo: Division = 'elite',
): { from: Division; to: Division } {
    const rawFrom = readQueryParam(search, 'currentDivision', ladder);
    const rawTo = readQueryParam(search, 'targetDivision', ladder);

    if (rawFrom !== undefined && rawTo !== undefined) {
        const fromIndex = ladder.indexOf(rawFrom);
        const toIndex = ladder.indexOf(rawTo);

        if (
            fromIndex >= 0 &&
            toIndex >= 0 &&
            fromIndex < ladder.length - 1 &&
            toIndex > fromIndex
        ) {
            return { from: rawFrom, to: rawTo };
        }
    }

    return { from: defaultFrom, to: defaultTo };
}

/**
 * Computes initial FUT Champions rank and urgent option from URL search string.
 * Invalid or missing params degrade to the specified defaults.
 */
export function getInitialFutChampionsConfig(
    search: unknown,
    rankOptions: readonly { rank: number }[],
    defaultRank = 3,
    defaultUrgent = false,
): { rank: number; urgent: boolean } {
    const rawRank = readNumericQueryParam(search, 'rank');
    const validRank =
        rawRank !== undefined &&
        rankOptions.some((entry) => entry.rank === rawRank)
            ? rawRank
            : defaultRank;

    const rawUrgent = readBooleanQueryParam(search, 'urgent');
    const validUrgent = rawUrgent !== undefined ? rawUrgent : defaultUrgent;

    return { rank: validRank, urgent: validUrgent };
}

/**
 * Computes initial Coins configuration from URL search string.
 * Validates platform, delivery mode, and quantity against offered bounds.
 */
export function getInitialCoinsConfig(
    search: unknown,
    amount: number | CoinsAmountRules,
    platforms?: readonly CoinsPlatformOption[],
): {
    deliveryValue: CoinsDeliveryValue | null;
    lastValidQuantity: number;
    platformValue: CoinsPlatformValue | null;
    step: CoinsStep;
} {
    const minimum = typeof amount === 'number' ? amount : amount.minimum;
    const increment = typeof amount === 'number' ? 10_000 : amount.increment;

    const rawPlatform = readQueryParam(search, 'platform', [
        'playstation',
        'pc',
    ] as const);

    const platformOption =
        rawPlatform !== undefined
            ? (platforms?.find((option) => option.value === rawPlatform) ??
              null)
            : null;
    const platformValue = platformOption?.value ?? null;

    let deliveryValue: CoinsDeliveryValue | null = null;

    if (platformValue === 'playstation') {
        const rawDelivery =
            readQueryParam(search, 'delivery', ['normal', 'fast'] as const) ??
            readQueryParam(search, 'deliveryMode', [
                'normal',
                'fast',
            ] as const) ??
            readQueryParam(search, 'delivery_mode', [
                'normal',
                'fast',
            ] as const);

        const deliveryOption =
            rawDelivery !== undefined
                ? (platformOption?.deliveries.find(
                      (delivery) => delivery.value === rawDelivery,
                  ) ?? null)
                : null;
        deliveryValue = deliveryOption?.value ?? null;
    }

    const rawQuantity =
        readNumericQueryParam(search, 'quantity') ??
        readNumericQueryParam(search, 'amount');

    let lastValidQuantity = minimum;

    if (rawQuantity !== undefined) {
        let maxAllowed = minimum;

        if (platformValue === 'pc') {
            maxAllowed = platformOption?.maximum ?? 2_000_000;
        } else if (platformValue === 'playstation' && deliveryValue !== null) {
            const chosenDelivery = platformOption?.deliveries.find(
                (d) => d.value === deliveryValue,
            );
            maxAllowed =
                chosenDelivery?.maximum ??
                platformOption?.maximum ??
                20_000_000;
        } else if (platformValue === 'playstation') {
            maxAllowed = platformOption?.maximum ?? 20_000_000;
        } else if (platforms && platforms.length > 0) {
            maxAllowed = Math.max(...platforms.map((p) => p.maximum));
        } else {
            maxAllowed = minimum;
        }

        const isOffered =
            rawQuantity >= minimum &&
            rawQuantity <= maxAllowed &&
            (rawQuantity - minimum) % increment === 0;

        if (isOffered) {
            lastValidQuantity = rawQuantity;
        }
    }

    let step: CoinsStep = 'platform';

    if (platformValue === 'pc') {
        step = 'amount';
    } else if (platformValue === 'playstation' && deliveryValue !== null) {
        step = 'amount';
    } else if (platformValue === 'playstation' && deliveryValue === null) {
        step = 'delivery';
    }

    return {
        deliveryValue,
        lastValidQuantity,
        platformValue,
        step,
    };
}
