// Runs Config and Prepare Coins Snapshot against the market of 2026-09-18, and
// pins the owner's rule from that night: a million coins does not cost more
// than 300 USD, and a quantity only a dearer rung can fill is not sold.
//
// The numbers are the real ones from run 01M2RX0SKGNMQ0BDWPTB6Z1CGW. The
// PlayStation cycle cleared at 189.2 USD a million and one targeted rung
// covered a million at 250; above that the only cover left in the book was
// 174.25 EUR/100K - 2,000 USD a million - and PC had nothing but a 78.41
// listing good for 168,300 coins. Priced straight through, that alert offered
// five million fast coins at 49,360 SAR and a million PC coins at 5,347.
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import { test } from 'node:test';

const workflow = JSON.parse(
    readFileSync(new URL('../workflow-v3.0.json', import.meta.url), 'utf8'),
);
const source = (name) =>
    workflow.nodes.find((node) => node.name === name).parameters.jsCode;
const AsyncFunction = Object.getPrototypeOf(async function () {}).constructor;

const RATIO = 1.14779;

/** EUR per 100K, from the USD per million the case is written in. */
const eurPer100K = (usdPerMillion) => usdPerMillion / (10 * RATIO);

async function runConfig() {
    const fn = new AsyncFunction(
        '$',
        '$input',
        '$env',
        '$getWorkflowStaticData',
        source('Config'),
    );
    const out = await fn(
        () => ({ first: () => ({ json: {} }) }),
        { first: () => ({ json: {} }), all: () => [] },
        {},
        () => ({}),
    );

    return Array.isArray(out) ? out[0].json : out.json;
}

async function runPrepare(config, fft, utt, memory) {
    const staticData = { global: { coinsPricingV2: memory } };
    const lookup = (name) => ({
        first: () => ({ json: name === 'Config' ? config : {} }),
    });
    const input = {
        all: () => [{ json: fft }, { json: utt }],
        first: () => ({ json: fft }),
    };
    const context = {
        helpers: {
            async httpRequest() {
                // No store baseline: this case is about the cost basis, and the
                // workflow memory below is the baseline it compares against.
                throw new Error('no baseline endpoint');
            },
        },
    };
    const prepare = source('Prepare Coins Snapshot');
    const fn = new AsyncFunction(
        '$',
        '$input',
        '$env',
        '$getWorkflowStaticData',
        'require',
        'return (async function () { ' + prepare + ' }).call(this);',
    );
    const out = await fn.call(
        context,
        lookup,
        input,
        { N8N_PRICING_SECRET: 'test-secret' },
        (scope) => staticData[scope],
        createRequire(import.meta.url),
    );

    return out[0].json;
}

/** The night of 2026-09-18, as the two probes reported it. */
function thatNight(caps) {
    const fft = {
        source: 'fft',
        psCycleOk: true,
        pcCycleOk: true,
        warnings: [],
        cycle: {
            ps: { platform: 'PS', usdPerM: 189.2, poolCoins: 95_910_842 },
            pc: { platform: 'PC', usdPerM: 378.3, poolCoins: 3_477_368 },
        },
        targeted: {
            ps: {
                // A million is covered at 250 USD. Two million is covered too,
                // but only at 2,000 - the dear end of a thin book - and above
                // that nothing is covered at all, so the same dear listing is
                // what the fallback reaches for.
                tiers: caps.map((targetK) => ({
                    targetK,
                    covered: targetK <= 2_000,
                    priceEurPer100K: eurPer100K(targetK === 1_000 ? 250 : 2_000),
                    maxK: targetK,
                })),
                highestAvailable: {
                    priceEurPer100K: 174.25,
                    capacityCoins: 2_040_287,
                },
            },
            pc: {
                tiers: caps.map((targetK) => ({ targetK, covered: false })),
                highestAvailable: {
                    priceEurPer100K: 78.41,
                    capacityCoins: 168_300,
                },
            },
        },
    };
    const utt = {
        source: 'utt',
        ratioEuroUsd: RATIO,
        ps: { tiers: [], highestAvailable: null },
        pc: { tiers: [], highestAvailable: null },
    };

    return { fft, utt };
}

const memory = {
    lastSuccessfulRates: {
        console_normal: 102_121,
        console_fast: [102_121, 111_073, 111_073, 111_073, 111_073, 111_073],
        pc: [108_452, 108_452, 108_452, 108_452, 108_452, 108_452],
    },
    lastCyclePSUsdPerM: 189.2,
    lastCyclePCUsdPerM: 378.3,
};

test('a tier nobody can fill under 300 USD a million is not offered', async () => {
    const config = await runConfig();
    const { fft, utt } = thatNight(config.settings.tierCapsK);
    const run = await runPrepare(config, fft, utt, memory);
    const audit = run.pricingAudit;

    assert.equal(run.valid, true);
    assert.equal(audit.costCeiling.usdPerMillion, 300);

    // Fast: the million rung is affordable, everything above it is not.
    assert.equal(audit.costCeiling.affordableCoins.console_fast, 1_000_000);
    assert.equal(
        run.snapshot.observations.availableCoins.console_fast,
        1_000_000,
        'the storefront stops offering fast coins above the affordable rung',
    );
    assert.deepEqual(
        audit.tierCosts.console_fast.map((row) => row.costCeilingExceeded),
        [false, true, true, true, true, true],
    );

    // PC: not one rung is affordable, so it sells nothing and keeps the last
    // rates it published - the budget fulfilment reads, not a price a customer
    // can reach.
    assert.equal(audit.costCeiling.affordableCoins.pc, 0);
    assert.equal(run.snapshot.observations.availableCoins.pc, 0);
    assert.deepEqual(
        run.snapshot.rules.pc.tier_rates_halalah_per_million,
        memory.lastSuccessfulRates.pc,
    );

    // Normal is priced from the cycle, which cleared well under the ceiling.
    assert.ok(run.snapshot.observations.availableCoins.console_normal > 0);
    assert.ok(
        run.snapshot.rules.console_normal.flat_rate_halalah_per_million <
            memory.lastSuccessfulRates.console_normal,
        'the normal rate still follows the market down',
    );
});

test('an unreachable tier never carries a price the market asked for', async () => {
    const config = await runConfig();
    const { fft, utt } = thatNight(config.settings.tierCapsK);
    const run = await runPrepare(config, fft, utt, memory);
    const rates = run.snapshot.rules.console_fast.tier_rates_halalah_per_million;

    // Every rung above the affordable one repeats it. Without this the table
    // published 9,631 SAR a million beside an availability cap - one bug away
    // from being charged.
    for (let index = 1; index < rates.length; index += 1) {
        assert.equal(rates[index], rates[0]);
    }
});

test('a market under the ceiling is priced tier by tier as before', async () => {
    const config = await runConfig();
    const caps = config.settings.tierCapsK;
    const { utt } = thatNight(caps);
    const fft = {
        source: 'fft',
        psCycleOk: true,
        pcCycleOk: true,
        warnings: [],
        cycle: {
            ps: { platform: 'PS', usdPerM: 189.2, poolCoins: 95_910_842 },
            pc: { platform: 'PC', usdPerM: 200, poolCoins: 20_000_000 },
        },
        targeted: {
            ps: {
                tiers: caps.map((targetK, index) => ({
                    targetK,
                    covered: true,
                    priceEurPer100K: eurPer100K(200 + index * 10),
                    maxK: targetK,
                })),
                highestAvailable: null,
            },
            pc: {
                tiers: caps.map((targetK) => ({
                    targetK,
                    covered: true,
                    priceEurPer100K: eurPer100K(210),
                    maxK: targetK,
                })),
                highestAvailable: null,
            },
        },
    };

    const run = await runPrepare(config, fft, utt, memory);
    const audit = run.pricingAudit;

    assert.equal(audit.costCeiling.affordableCoins.console_fast, 20_000_000);
    assert.equal(audit.costCeiling.affordableCoins.pc, 20_000_000);
    assert.ok(run.snapshot.observations.availableCoins.pc > 0);
});

/** Runs Assess Price Move over what Validate Snapshot would hand it. */
async function runAssess(prepared) {
    const item = {
        valid: true,
        failureReason: null,
        coinsPricingSnapshot: prepared.snapshot,
        pricingAudit: prepared.pricingAudit,
    };
    const fn = new AsyncFunction(
        '$input',
        '$getWorkflowStaticData',
        source('Assess Price Move'),
    );
    const out = await fn(
        { first: () => ({ json: item }) },
        () => ({}),
    );

    return out[0].json;
}

/** A market that moves every price by one fraction of itself. */
function marketAt(caps, usdPerMillion) {
    const fft = {
        source: 'fft',
        psCycleOk: true,
        pcCycleOk: true,
        warnings: [],
        cycle: {
            ps: { platform: 'PS', usdPerM: usdPerMillion, poolCoins: 95_910_842 },
            pc: { platform: 'PC', usdPerM: usdPerMillion, poolCoins: 95_910_842 },
        },
        targeted: {
            ps: {
                tiers: caps.map((targetK) => ({
                    targetK,
                    covered: true,
                    priceEurPer100K: eurPer100K(usdPerMillion),
                    maxK: targetK,
                })),
                highestAvailable: null,
            },
            pc: {
                tiers: caps.map((targetK) => ({
                    targetK,
                    covered: true,
                    priceEurPer100K: eurPer100K(usdPerMillion),
                    maxK: targetK,
                })),
                highestAvailable: null,
            },
        },
    };
    const utt = {
        source: 'utt',
        ratioEuroUsd: RATIO,
        ps: { tiers: [], highestAvailable: null },
        pc: { tiers: [], highestAvailable: null },
    };

    return { fft, utt };
}

test('an ordinary move does not reach for the phone', async () => {
    const config = await runConfig();
    const caps = config.settings.tierCapsK;

    // Publish once to make a baseline, then move the whole book by a tenth.
    const first = await runPrepare(config, ...Object.values(marketAt(caps, 200)), {});
    const baseline = {
        lastSuccessfulRates: {
            console_normal:
                first.snapshot.rules.console_normal.flat_rate_halalah_per_million,
            console_fast: [
                ...first.snapshot.rules.console_fast.tier_rates_halalah_per_million,
            ],
            pc: [...first.snapshot.rules.pc.tier_rates_halalah_per_million],
        },
        lastCyclePSUsdPerM: 200,
        lastCyclePCUsdPerM: 200,
    };

    const second = await runPrepare(
        config,
        ...Object.values(marketAt(caps, 180)),
        baseline,
    );
    const assessed = await runAssess(second);

    // Every price moved, and not one of them moved by a quarter of the million
    // price - which under the old rule tripped the moment a 10K row crossed
    // ten riyals.
    assert.ok(assessed.priceMove.comparedCount > 0);
    assert.ok(assessed.priceMove.moves.every((move) => move.deltaSar > 0));
    assert.equal(assessed.priceMove.largeCount, 0);
    assert.equal(assessed.approvalRequired, false);
});

test('a move worth more than a quarter of a million asks first', async () => {
    const config = await runConfig();
    const caps = config.settings.tierCapsK;

    const first = await runPrepare(config, ...Object.values(marketAt(caps, 200)), {});
    const baseline = {
        lastSuccessfulRates: {
            console_normal:
                first.snapshot.rules.console_normal.flat_rate_halalah_per_million,
            console_fast: [
                ...first.snapshot.rules.console_fast.tier_rates_halalah_per_million,
            ],
            pc: [...first.snapshot.rules.pc.tier_rates_halalah_per_million],
        },
        lastCyclePSUsdPerM: 200,
        lastCyclePCUsdPerM: 200,
    };

    // Coins nearly half again as dear: the million row alone moves by far more
    // than a quarter of what a million costs.
    const second = await runPrepare(
        config,
        ...Object.values(marketAt(caps, 290)),
        baseline,
    );
    const assessed = await runAssess(second);

    assert.equal(assessed.approvalRequired, true);
    assert.ok(assessed.priceMove.largeCount > 0);
    assert.equal(assessed.priceMove.thresholdBasis, 'million_price');
    assert.match(assessed.telegramMessage, /ربع سعر المليون الحالي/);

    // The yardstick is recorded beside the move it judged.
    for (const move of assessed.priceMove.moves) {
        assert.ok(move.millionSar > 0);
    }
});
