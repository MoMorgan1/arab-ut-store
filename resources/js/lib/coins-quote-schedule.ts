import type {
    CoinsAmountRules,
    CoinsDeliveryValue,
    CoinsPlatformOption,
    CoinsPlatformValue,
    CoinsQuote,
    CoinsQuoteSchedule,
    CoinsQuoteScheduleKey,
    CoinsQuoteSchedules,
} from '@/types/coins';

type JsonRecord = Record<string, unknown>;

type ExpectedSchedule = {
    delivery: CoinsDeliveryValue | null;
    market: 'console' | 'pc';
    maximum: number;
    platform: CoinsPlatformValue;
};

const SCHEDULE_KEYS: CoinsQuoteScheduleKey[] = [
    'playstation:normal',
    'playstation:fast',
    'pc',
];
const ULID_PATTERN = /^[0-7][0-9A-HJKMNP-TV-Z]{25}$/;
const CURRENCY_PATTERN = /^[A-Z]{3}$/;
const UTC_TIMESTAMP_PATTERN =
    /^(\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2})(?:\.(\d{1,3}))?(?:Z|\+00:00)$/;

function unavailableSchedules(): CoinsQuoteSchedules {
    return {
        pc: null,
        'playstation:fast': null,
        'playstation:normal': null,
    };
}

function isRecord(candidate: unknown): candidate is JsonRecord {
    return (
        typeof candidate === 'object' &&
        candidate !== null &&
        !Array.isArray(candidate)
    );
}

function isPositiveSafeInteger(candidate: unknown): candidate is number {
    return (
        typeof candidate === 'number' &&
        Number.isSafeInteger(candidate) &&
        candidate > 0
    );
}

function isUlid(candidate: unknown): candidate is string {
    return typeof candidate === 'string' && ULID_PATTERN.test(candidate);
}

function isUtcTimestamp(candidate: unknown): candidate is string {
    if (typeof candidate !== 'string') {
        return false;
    }

    const match = UTC_TIMESTAMP_PATTERN.exec(candidate);

    if (match === null) {
        return false;
    }

    const normalized = `${match[1]}.${(match[2] ?? '').padEnd(3, '0')}Z`;
    const timestamp = new Date(candidate);

    return (
        Number.isFinite(timestamp.getTime()) &&
        timestamp.toISOString() === normalized
    );
}

function hasExactScheduleKeys(candidate: JsonRecord): boolean {
    const receivedKeys = Object.keys(candidate).sort();
    const expectedKeys = [...SCHEDULE_KEYS].sort();

    return (
        receivedKeys.length === expectedKeys.length &&
        receivedKeys.every((key, index) => key === expectedKeys[index])
    );
}

function expectedSchedules(
    platforms: readonly CoinsPlatformOption[],
): Record<CoinsQuoteScheduleKey, ExpectedSchedule> | null {
    const consolePlatform = platforms.find(
        (platform) => platform.value === 'playstation',
    );
    const pcPlatform = platforms.find((platform) => platform.value === 'pc');
    const normalDelivery = consolePlatform?.deliveries.find(
        (delivery) => delivery.value === 'normal',
    );
    const fastDelivery = consolePlatform?.deliveries.find(
        (delivery) => delivery.value === 'fast',
    );

    if (
        consolePlatform === undefined ||
        pcPlatform === undefined ||
        normalDelivery === undefined ||
        fastDelivery === undefined
    ) {
        return null;
    }

    return {
        pc: {
            delivery: null,
            market: 'pc',
            maximum: pcPlatform.maximum,
            platform: 'pc',
        },
        'playstation:fast': {
            delivery: 'fast',
            market: 'console',
            maximum: fastDelivery.maximum,
            platform: 'playstation',
        },
        'playstation:normal': {
            delivery: 'normal',
            market: 'console',
            maximum: normalDelivery.maximum,
            platform: 'playstation',
        },
    };
}

function hasValidBounds(
    candidate: JsonRecord,
    amount: CoinsAmountRules,
    expected: ExpectedSchedule,
): boolean {
    return (
        candidate.minimum === amount.minimum &&
        candidate.maximum === expected.maximum &&
        candidate.increment === amount.increment &&
        isPositiveSafeInteger(candidate.minimum) &&
        isPositiveSafeInteger(candidate.maximum) &&
        isPositiveSafeInteger(candidate.increment) &&
        (candidate.maximum - candidate.minimum) % candidate.increment === 0
    );
}

function hasValidIdentity(
    productId: unknown,
    variantId: unknown,
    priceVersion: unknown,
    pricedAt: unknown,
): boolean {
    return (
        isUlid(productId) &&
        isUlid(variantId) &&
        isPositiveSafeInteger(priceVersion) &&
        isUtcTimestamp(pricedAt)
    );
}

function hasValidTotals(candidate: JsonRecord): boolean {
    if (
        !Array.isArray(candidate.totalsHalalah) ||
        !Array.isArray(candidate.displayTotalsMinor)
    ) {
        return false;
    }

    const expectedLength =
        (Number(candidate.maximum) - Number(candidate.minimum)) /
            Number(candidate.increment) +
        1;

    return (
        candidate.totalsHalalah.length === expectedLength &&
        candidate.displayTotalsMinor.length === expectedLength &&
        candidate.totalsHalalah.every(isPositiveSafeInteger) &&
        candidate.displayTotalsMinor.every(isPositiveSafeInteger)
    );
}

function parseSchedule(
    candidate: unknown,
    displayCurrency: string,
    amount: CoinsAmountRules,
    expected: ExpectedSchedule,
): CoinsQuoteSchedule | null {
    if (!isRecord(candidate)) {
        return null;
    }

    if (
        candidate.platform !== expected.platform ||
        candidate.delivery !== expected.delivery ||
        candidate.market !== expected.market ||
        candidate.displayCurrency !== displayCurrency ||
        !CURRENCY_PATTERN.test(displayCurrency) ||
        !hasValidBounds(candidate, amount, expected) ||
        !hasValidIdentity(
            candidate.productId,
            candidate.variantId,
            candidate.priceVersion,
            candidate.pricedAt,
        ) ||
        !hasValidTotals(candidate)
    ) {
        return null;
    }

    return candidate as CoinsQuoteSchedule;
}

export function parseCoinsQuoteSchedules(
    payload: unknown,
    displayCurrency: string,
    amount: CoinsAmountRules,
    platforms: readonly CoinsPlatformOption[],
): CoinsQuoteSchedules {
    if (!isRecord(payload) || !hasExactScheduleKeys(payload)) {
        return unavailableSchedules();
    }

    const expected = expectedSchedules(platforms);

    if (expected === null) {
        return unavailableSchedules();
    }

    return {
        pc: parseSchedule(payload.pc, displayCurrency, amount, expected.pc),
        'playstation:fast': parseSchedule(
            payload['playstation:fast'],
            displayCurrency,
            amount,
            expected['playstation:fast'],
        ),
        'playstation:normal': parseSchedule(
            payload['playstation:normal'],
            displayCurrency,
            amount,
            expected['playstation:normal'],
        ),
    };
}

function scheduleHeaderIsValid(schedule: CoinsQuoteSchedule): boolean {
    return (
        isPositiveSafeInteger(schedule.minimum) &&
        isPositiveSafeInteger(schedule.maximum) &&
        isPositiveSafeInteger(schedule.increment) &&
        schedule.maximum >= schedule.minimum &&
        (schedule.maximum - schedule.minimum) % schedule.increment === 0 &&
        CURRENCY_PATTERN.test(schedule.displayCurrency) &&
        hasValidIdentity(
            schedule.productId,
            schedule.variantId,
            schedule.priceVersion,
            schedule.pricedAt,
        )
    );
}

function scheduleTupleIsValid(schedule: CoinsQuoteSchedule): boolean {
    return schedule.platform === 'pc'
        ? schedule.market === 'pc' && schedule.delivery === null
        : schedule.platform === 'playstation' &&
              schedule.market === 'console' &&
              (schedule.delivery === 'normal' || schedule.delivery === 'fast');
}

function hasExpectedArrayLengths(schedule: CoinsQuoteSchedule): boolean {
    const expectedLength =
        (schedule.maximum - schedule.minimum) / schedule.increment + 1;

    return (
        schedule.totalsHalalah.length === expectedLength &&
        schedule.displayTotalsMinor.length === expectedLength
    );
}

function isLegalQuantity(
    schedule: CoinsQuoteSchedule,
    quantity: number,
): boolean {
    return (
        Number.isSafeInteger(quantity) &&
        quantity >= schedule.minimum &&
        quantity <= schedule.maximum &&
        (quantity - schedule.minimum) % schedule.increment === 0
    );
}

export function quoteFromSchedule(
    schedule: CoinsQuoteSchedule,
    quantity: number,
): CoinsQuote | null {
    if (!scheduleHeaderIsValid(schedule) || !scheduleTupleIsValid(schedule)) {
        return null;
    }

    if (
        !hasExpectedArrayLengths(schedule) ||
        !isLegalQuantity(schedule, quantity)
    ) {
        return null;
    }

    const index = (quantity - schedule.minimum) / schedule.increment;
    const amountHalalah = schedule.totalsHalalah[index];
    const amountMinor = schedule.displayTotalsMinor[index];

    if (
        !isPositiveSafeInteger(amountHalalah) ||
        !isPositiveSafeInteger(amountMinor)
    ) {
        return null;
    }

    return {
        delivery: schedule.delivery,
        displayTotal: {
            amountMinor,
            currency: schedule.displayCurrency,
        },
        market: schedule.market,
        platform: schedule.platform,
        pricedAt: schedule.pricedAt,
        priceVersion: schedule.priceVersion,
        productId: schedule.productId,
        quantity,
        total: { amountHalalah, currency: 'SAR' },
        variantId: schedule.variantId,
    };
}
