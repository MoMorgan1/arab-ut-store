import { createRequire } from 'node:module';
import { readFile } from 'node:fs/promises';

const root = new URL('../', import.meta.url);
const require = createRequire(import.meta.url);

export const NOW = '2026-08-28T12:00:00.000Z';
export const NOW_SECONDS = Math.floor(new Date(NOW).getTime() / 1000);
export const FAR_FUTURE = NOW_SECONDS + 14 * 24 * 3600;

export async function nodeSource(name) {
    return readFile(new URL(`nodes/${name}.js`, root), 'utf8');
}

function fixedDate(now) {
    return class FixedDate extends Date {
        constructor(value) {
            super(value ?? now);
        }

        static now() {
            return new Date(now).getTime();
        }
    };
}

function toItems(value) {
    const list = Array.isArray(value) ? value : [value];

    return list.map((entry) =>
        entry && Object.hasOwn(entry, 'json') ? entry : { json: entry },
    );
}

/**
 * Runs the v4 Code nodes against one shared set of outputs, so `$('Node Name')`
 * resolves exactly as it does in n8n. v4 leans on cross-node lookups far more
 * than v3 did, and most of the interesting failures only appear when several
 * nodes run in sequence against the same data.
 */
export function pipeline({ env = {}, staticData = {}, now = NOW } = {}) {
    const outputs = new Map();
    const FixedDate = fixedDate(now);

    async function run(nodeName, sourceName, input) {
        const items = toItems(input ?? []);
        const source = await nodeSource(sourceName);
        const lookup = (name) => {
            if (!outputs.has(name)) {
                throw new Error(`node "${name}" has not executed`);
            }
            const produced = outputs.get(name);

            return {
                first: () => produced[0],
                last: () => produced[produced.length - 1],
                all: () => produced,
            };
        };
        const runner = new Function(
            '$',
            '$input',
            '$env',
            '$getWorkflowStaticData',
            'require',
            'Date',
            source,
        );
        const produced = toItems(
            runner(
                lookup,
                {
                    first: () => items[0] ?? { json: {} },
                    last: () => items[items.length - 1] ?? { json: {} },
                    all: () => items,
                },
                env,
                () => staticData,
                require,
                FixedDate,
            ),
        );

        outputs.set(nodeName, produced);

        return produced;
    }

    return {
        run,
        set: (nodeName, value) => outputs.set(nodeName, toItems(value)),
        get: (nodeName) => outputs.get(nodeName),
        json: (nodeName) => outputs.get(nodeName)[0].json,
        staticData,
    };
}

export function env(overrides = {}) {
    return {
        N8N_SBC_PRICING_READ_SECRET: 'pricing-secret',
        N8N_SBC_CATALOG_SECRET: 'catalog-secret',
        FFT_API_USER: 'fixture@example.com',
        FFT_API_KEY: 'fixture-key',
        ...overrides,
    };
}

export function pricingRead(overrides = {}) {
    return {
        schemaVersion: 1,
        pricingVersion: 7,
        pricedAt: NOW,
        quotes: {
            playstation_fast: {
                platform: 'playstation',
                delivery: 'fast',
                quantity: 1_000_000,
                totalHalalah: 5_500,
            },
            pc: {
                platform: 'pc',
                delivery: null,
                quantity: 1_000_000,
                totalHalalah: 5_200,
            },
        },
        ...overrides,
    };
}

/** FuTTransfer: availability and coin-price authority. Keyed by setID. */
export function fftRecord(index, overrides = {}) {
    return {
        setID: index,
        sbcName: `Test SBC ${index}`,
        challengeAmount: (index % 4) + 1,
        // FFT sends a datetime string, not an epoch. Real production value.
        expiry: '2035-07-30 19:00:00',
        consolePrice: 40_000 + index * 1_000,
        pcPrice: 38_000 + index * 900,
        ...overrides,
    };
}

export function fftRecords(count = 130, overrides = {}) {
    return Array.from({ length: count }, (_, index) =>
        fftRecord(index + 1, overrides),
    );
}

/** EasySBC: metadata and the cross-source identity check. Keyed by id. */
export function metaRecord(index, overrides = {}) {
    const repeatable = index % 2 === 0;

    return {
        id: index,
        name: `Test SBC ${index}`,
        slug: `test-sbc-${index}`,
        categoryId: (index % 3) + 1,
        description: `Complete Test SBC ${index}.`,
        sbcsCount: (index % 4) + 1,
        repeatable,
        repeats: repeatable ? null : 1,
        repeatabilityMode: repeatable ? 'UNLIMITED' : 'NON_REPEATABLE',
        endTime: FAR_FUTURE,
        active: true,
        psPrice: 40_000 + index * 1_000,
        pcPrice: 38_000 + index * 900,
        imageURL: `https://assets.easysbc.io/fc26/sbcs/sets/icons/${index}.png`,
        ...overrides,
    };
}

export function metaRecords(count = 120, unusableCount = 0) {
    const records = Array.from({ length: count }, (_, index) =>
        metaRecord(index + 1),
    );

    // Unusable means no identity at all. A missing pcPrice is NOT unusable:
    // FFT prices anything it lists, so a priceless EasySBC row is still good
    // metadata. That distinction is what recovers the Marcelo/Rafael Leao case.
    for (let offset = 0; offset < unusableCount; offset += 1) {
        records.push(metaRecord(900 + offset, { name: '' }));
    }

    return records;
}

export function httpOk(body) {
    return { statusCode: 200, body };
}

/** The shape n8n hands back when it does not parse the body itself. */
export function asBuffer(value) {
    return {
        type: 'Buffer',
        data: Array.from(new TextEncoder().encode(JSON.stringify(value))),
    };
}

export function translationAnswer(plan) {
    return plan.missingTranslations.map(({ id, sourceName }) => ({
        id,
        sourceName,
        nameAr: `ترقية ${id} لاعبين +85`,
    }));
}

/**
 * Config through Build & Price Snapshot, which is every node that touches
 * provider data or money.
 */
export async function runToSnapshot({
    fft = fftRecords(),
    meta = metaRecords(),
    staticData = {},
    environment = env(),
    poisonConfig = () => {},
} = {}) {
    const flow = pipeline({ env: environment, staticData });

    await flow.run('Config', 'config', [{}]);
    poisonConfig(flow.json('Config'));

    flow.set('Read Coins Bases', httpOk(pricingRead()));
    await flow.run(
        'Evaluate Pricing Read',
        'evaluate-pricing-read',
        flow.get('Read Coins Bases'),
    );

    flow.set('Fetch FFT SBCs', httpOk(fft));
    flow.set('Fetch EasySBC Sets', httpOk(meta));
    await flow.run(
        'Merge Provider Sources',
        'merge-sources',
        flow.get('Fetch EasySBC Sets'),
    );
    await flow.run(
        'Plan Translations',
        'plan-translations',
        flow.get('Merge Provider Sources'),
    );

    const plan = flow.json('Plan Translations');

    if (plan.translationReady) {
        await flow.run(
            'Build & Price Snapshot',
            'build-and-price',
            flow.get('Plan Translations'),
        );

        return flow;
    }

    flow.set('Translate SBC Names', {
        text: JSON.stringify(translationAnswer(plan)),
    });
    await flow.run(
        'Validate Translations',
        'validate-translations',
        flow.get('Translate SBC Names'),
    );
    await flow.run(
        'Build & Price Snapshot',
        'build-and-price',
        flow.get('Validate Translations'),
    );

    return flow;
}

/** The full run, ending in an accepted publish. */
export async function runToPublish(options = {}) {
    const flow = await runToSnapshot(options);

    await flow.run(
        'Validate Snapshot',
        'validate-snapshot',
        flow.get('Build & Price Snapshot'),
    );
    await flow.run(
        'Sign Catalog Snapshot',
        'sign-catalog',
        flow.get('Validate Snapshot'),
    );

    const snapshot = flow.json('Sign Catalog Snapshot').catalogSnapshot;

    flow.set('Publish SBC Catalog', {
        statusCode: 201,
        body: {
            data: {
                runId: snapshot.runId,
                status: 'completed',
                applied: snapshot.products.length,
                archived: 0,
            },
        },
    });
    await flow.run('Finish Run', 'finish-run', flow.get('Publish SBC Catalog'));

    return flow;
}

/**
 * A transcription of the store's
 * app/ValueObjects/Pricing/SbcCompletionPricing.php::expectedTiers().
 * The store compares these with !==, so the workflow has to match exactly.
 */
export const LARAVEL_STANDARD_TIERS = [
    [5, 10_000],
    [10, 9_500],
    [15, 9_200],
    [20, 9_000],
    [30, 8_700],
    [40, 8_500],
    [50, 8_200],
    [75, 7_800],
    [100, 7_600],
];

export function laravelExpectedTiers(repeatable, maximum) {
    if (!repeatable) {
        return [[1, 10_000]];
    }
    if (maximum !== null && maximum < 5) {
        return Array.from({ length: maximum }, (_, index) => [
            index + 1,
            10_000,
        ]);
    }
    if (maximum === null || maximum >= 100) {
        return LARAVEL_STANDARD_TIERS;
    }

    const tiers = LARAVEL_STANDARD_TIERS.filter(
        ([completions]) => completions <= maximum,
    );
    const last = tiers[tiers.length - 1];

    if (last[0] !== maximum) {
        tiers.push([maximum, Math.max(7_000, last[1] - 200)]);
    }

    return tiers;
}

/** Returns null when the store would accept the variant, or the reason it would not. */
export function laravelWouldReject(configuration, fallbackMinor) {
    const exact = (value, expected) =>
        JSON.stringify(Object.keys(value).sort()) ===
        JSON.stringify([...expected].sort());

    if (fallbackMinor <= 0) {
        return 'completion price must be positive';
    }

    const pricing = configuration.completionPricing;

    if (!pricing) {
        return 'completion pricing must be declared';
    }
    if (!exact(pricing, ['version', 'repeatable', 'maximum', 'tiers'])) {
        return 'completion pricing contains unsupported fields';
    }
    if (pricing.version !== 1 || typeof pricing.repeatable !== 'boolean') {
        return 'completion pricing identity is malformed';
    }
    if (!pricing.repeatable && pricing.maximum !== 1) {
        return 'a nonrepeatable SBC must have a maximum of one';
    }
    if (
        pricing.repeatable &&
        pricing.maximum !== null &&
        (!Number.isInteger(pricing.maximum) || pricing.maximum < 2)
    ) {
        return 'a repeatable SBC maximum must be null or at least two';
    }

    const expected = laravelExpectedTiers(pricing.repeatable, pricing.maximum);

    if (
        !Array.isArray(pricing.tiers) ||
        pricing.tiers.length !== expected.length
    ) {
        return `completion tiers have an invalid shape (${pricing.tiers?.length} vs ${expected.length})`;
    }

    for (const [index, tier] of pricing.tiers.entries()) {
        if (!exact(tier, ['completions', 'multiplierBps', 'totalMinor'])) {
            return 'a completion tier contains unsupported fields';
        }
        if (
            tier.completions !== expected[index][0] ||
            tier.multiplierBps !== expected[index][1] ||
            !Number.isInteger(tier.totalMinor) ||
            tier.totalMinor <= 0
        ) {
            return `tier is outside the supported policy: got [${tier.completions}, ${tier.multiplierBps}] want [${expected[index][0]}, ${expected[index][1]}]`;
        }
    }

    if (pricing.tiers[0].totalMinor !== fallbackMinor) {
        return 'the first completion tier must match the variant price';
    }

    return null;
}
