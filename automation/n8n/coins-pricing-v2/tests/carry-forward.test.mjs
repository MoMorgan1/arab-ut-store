// Runs the Config and Prepare Coins Snapshot nodes of workflow-v3.0.json the
// way n8n would - once with FFT answering, once with FFT down - and pins the
// v2.5 rule: FFT down carries last time's rates forward and still publishes a
// UTT cost table; with nothing to carry forward it stops as v2.4 did.
import assert from 'node:assert/strict';
import { createHmac } from 'node:crypto';
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import { test } from 'node:test';

const workflow = JSON.parse(readFileSync(new URL('../workflow-v3.0.json', import.meta.url), 'utf8'));
const source = (name) => workflow.nodes.find((node) => node.name === name).parameters.jsCode;
const configSrc = source('Config');
const prepareSrc = source('Prepare Coins Snapshot');
const AsyncFunction = Object.getPrototypeOf(async function () {}).constructor;

async function runConfig() {
    const fn = new AsyncFunction('$', '$input', '$env', '$getWorkflowStaticData', configSrc);
    const out = await fn(() => ({ first: () => ({ json: {} }) }), { first: () => ({ json: {} }), all: () => [] }, {}, () => ({}));

    return Array.isArray(out) ? out[0].json : out.json;
}

function tiers(caps, price, covered = true) {
    return caps.map((targetK) => ({ targetK, covered, priceEurPer100K: price, maxTransferable: targetK * 1000 * 3 }));
}

/**
 * `store` stands in for the baseline endpoint the node calls when the workflow
 * memory is empty - which is every manual execution, since n8n hands those an
 * empty static data object. `null` is a store that answers nothing.
 */
async function runPrepare(config, fft, utt, memory, store = null) {
    const staticData = { global: { coinsPricingV2: memory } };
    const lookup = (name) => ({ first: () => ({ json: name === 'Config' ? config : {} }) });
    const input = { all: () => [{ json: fft }, { json: utt }], first: () => ({ json: fft }) };
    const calls = [];
    const context = {
        helpers: {
            async httpRequest(options) {
                calls.push(options);

                if (!store) {
throw new Error('no baseline endpoint');
}

                return store;
            },
        },
    };
    // `require` is a module-scope binding, and an AsyncFunction is compiled in
    // global scope where it does not exist. n8n's Code node has it; the
    // harness has to hand it over or the signing below silently falls back.
    const fn = new AsyncFunction(
        '$',
        '$input',
        '$env',
        '$getWorkflowStaticData',
        'require',
        `return (async function () { ${prepareSrc} }).call(this);`,
    );
    const out = await fn.call(
        context,
        lookup,
        input,
        { N8N_PRICING_SECRET: 'test-secret' },
        (scope) => staticData[scope],
        createRequire(import.meta.url),
    );

    const json = out[0].json;
    json.__baselineCalls = calls;

    return json;
}

test('FFT down carries the last published rates forward and still publishes UTT tier costs', async () => {
    const config = await runConfig();
    const caps = config.settings.tierCapsK;
    const ratio = 1.15958;
    const utt = {
        source: 'utt', ratioEuroUsd: ratio,
        ps: { tiers: tiers(caps, 0.04), highestAvailable: null },
        pc: { tiers: tiers(caps, 0.26), highestAvailable: null },
    };
    const fftUp = {
        source: 'fft', psCycleOk: true, pcCycleOk: true, warnings: [],
        cycle: { ps: { platform: 'PS', usdPerM: 0.2 }, pc: { platform: 'PC', usdPerM: 0.2 } },
        targeted: { ps: { tiers: tiers(caps, 0.05) }, pc: { tiers: tiers(caps, 0.3) } },
    };
    const fftDown = {
        source: 'fft', failed: true, psCycleOk: false, pcCycleOk: false, warnings: [],
        failureReason: 'FFT PS cycle cost is unavailable',
        cycle: { ps: { platform: 'PS', usdPerM: null, failureReason: 'No FFT cycle clearing price was found' }, pc: { platform: 'PC', usdPerM: null } },
        targeted: { ps: [], pc: [] },
    };

    // 1. FFT up, no memory: behaves like v2.4 (a real run).
    const first = await runPrepare(config, fftUp, utt, {});
    assert.equal(first.valid, true);
    assert.equal(first.snapshot.observations.carryForward, false);
    assert.equal(first.snapshot.observations.source, 'fft+utt-v2');
    assert.equal(first.snapshot.observations.cyclePSUsdPerM, 0.2);
    const rates = {
        console_normal: first.snapshot.rules.console_normal.flat_rate_halalah_per_million,
        console_fast: [...first.snapshot.rules.console_fast.tier_rates_halalah_per_million],
        pc: [...first.snapshot.rules.pc.tier_rates_halalah_per_million],
    };

    // 2. FFT down, no memory: stops as before.
    await assert.rejects(runPrepare(config, fftDown, utt, {}), /No FFT cycle clearing price was found and no published rates exist to carry forward/);

    // 3. FFT down with memory: same rates, fresh UTT tier costs, remembered cycle.
    const memory = { lastSuccessfulRates: rates, lastCyclePSUsdPerM: 0.2, lastCyclePCUsdPerM: 0.2 };
    const third = await runPrepare(config, fftDown, utt, memory);
    assert.equal(third.valid, true);
    assert.equal(third.snapshot.observations.carryForward, true);
    assert.equal(third.snapshot.observations.source, 'utt+carry-forward-v2.5');
    assert.match(third.snapshot.observations.carryForwardReason, /No FFT cycle clearing price/);
    assert.equal(third.snapshot.observations.cyclePSUsdPerM, 0.2);
    assert.equal(third.snapshot.rules.console_normal.flat_rate_halalah_per_million, rates.console_normal);
    assert.deepEqual(third.snapshot.rules.console_fast.tier_rates_halalah_per_million, rates.console_fast);
    assert.deepEqual(third.snapshot.rules.pc.tier_rates_halalah_per_million, rates.pc);
    assert.deepEqual(third.snapshot.rules.console_fast.exact_overrides_halalah, first.snapshot.rules.console_fast.exact_overrides_halalah);
    const tc = third.snapshot.observations.tierCosts;
    assert.equal(tc.console_fast.length, caps.length);
    assert.ok(tc.console_fast.every((row) => row.rawUsdPerM === Math.round(0.04 * 10 * ratio * 10) / 10 && /utt_ps/.test(row.selectedSource)), JSON.stringify(tc.console_fast));
    assert.ok(tc.pc.every((row) => /utt_pc/.test(row.selectedSource)));
    assert.equal(third.pricingAudit.carryForward, true);

    // 4. FFT down, memory without cycle costs (first carry-forward after upgrade): cycle costs null, still publishes.
    const fourth = await runPrepare(config, fftDown, utt, { lastSuccessfulRates: rates });
    assert.equal(fourth.snapshot.observations.cyclePSUsdPerM, null);
});

test('the snapshot publishes what each group could actually be filled with', async () => {
    const config = await runConfig();
    const caps = config.settings.tierCapsK;
    const utt = {
        source: 'utt', ratioEuroUsd: 1.15958,
        ps: { tiers: tiers(caps, 0.04), highestAvailable: null },
        pc: { tiers: tiers(caps, 0.26), highestAvailable: null },
    };
    const fft = {
        source: 'fft', failed: false,
        cycle: {
            // The real FC27 opening: 45,000 coins on console, nothing on PC.
            ps: { usdPerM: 300, amountCoins: 45_000, poolCoins: 312_929, coversTarget: false },
            pc: { usdPerM: null, amountCoins: 0, poolCoins: 0, coversTarget: false },
        },
        targeted: {
            ps: { tiers: tiers(caps, null, false), highestAvailable: null },
            pc: { tiers: tiers(caps, null, false), highestAvailable: null },
        },
    };

    const out = await runPrepare(config, fft, utt, {});

    assert.equal(out.valid, true);
    // Half of the 312,929-coin pool, not the 45,000 one buy would fill.
    assert.deepEqual(out.snapshot.observations.availableCoins, {
        console_normal: 156_464,
        console_fast: 156_464,
        // Zero is published as zero. A platform with no market has to be able
        // to say so, or the storefront keeps offering twenty million coins.
        pc: 0,
    });
    // legalRanges is checked for equality against the store's own settings and
    // must therefore stay exactly as configured, whatever the market says.
    assert.equal(out.snapshot.legalRanges.pc.maximum, 20_000_000);

    // The approval alert quotes these, so they stop where the storefront does.
    // Quoting a twenty-million-coin price on a market holding three hundred
    // thousand reads as a pricing disaster and hides the rows for sale.
    assert.deepEqual(
        out.pricingAudit.pricePoints.console_fast.map((point) => point.quantity),
        [10_000, 100_000],
    );
    assert.deepEqual(
        out.pricingAudit.pricePoints.console_normal.map((point) => point.quantity),
        [10_000, 100_000],
    );
    assert.deepEqual(out.pricingAudit.pricePoints.pc, []);
});

test('a ceiling above what the store sells is clamped, never widened', async () => {
    const config = await runConfig();
    const caps = config.settings.tierCapsK;
    const utt = {
        source: 'utt', ratioEuroUsd: 1.15958,
        ps: { tiers: tiers(caps, 0.04), highestAvailable: null },
        pc: { tiers: tiers(caps, 0.26), highestAvailable: null },
    };
    const fft = {
        source: 'fft', failed: false,
        cycle: {
            ps: { usdPerM: 12, amountCoins: 90_000_000, poolCoins: 180_000_000, coversTarget: true },
            pc: { usdPerM: 20, amountCoins: 90_000_000, poolCoins: 180_000_000, coversTarget: true },
        },
        targeted: {
            ps: { tiers: tiers(caps, null, false), highestAvailable: null },
            pc: { tiers: tiers(caps, null, false), highestAvailable: null },
        },
    };

    const out = await runPrepare(config, fft, utt, {});

    assert.deepEqual(out.snapshot.observations.availableCoins, {
        console_normal: 2_000_000,
        console_fast: 20_000_000,
        pc: 20_000_000,
    });
});

test('a platform nobody quoted keeps the other one selling, and sells nothing itself', async () => {
    // FC27 opening: FFT's targeted book uncovered on PC, UTT's lots empty on
    // both, console with a real cycle price. v2.6 stopped the whole run over
    // PC and the store stayed on last season's rates for twelve days.
    const config = await runConfig();
    const caps = config.settings.tierCapsK;
    const utt = {
        source: 'utt', ratioEuroUsd: 1.15958,
        ps: { tiers: tiers(caps, 0.04), highestAvailable: null },
        pc: { tiers: tiers(caps, null, false), highestAvailable: null },
    };
    const fft = {
        source: 'fft', failed: false,
        cycle: {
            ps: { usdPerM: 220, amountCoins: 45_000, poolCoins: 312_929, coversTarget: false },
            pc: { usdPerM: null, amountCoins: 0, poolCoins: 0, coversTarget: false },
        },
        targeted: {
            ps: { tiers: tiers(caps, null, false), highestAvailable: null },
            pc: { tiers: tiers(caps, null, false), highestAvailable: null },
        },
    };
    const memory = {
        lastSuccessfulRates: {
            console_normal: 722,
            console_fast: [722, 722, 722, 914, 1000, 1100],
            pc: [1777, 1836, 1836, 2132, 3968, 3968],
        },
    };

    const out = await runPrepare(config, fft, utt, memory);

    assert.equal(out.valid, true, out.failureReason ?? '');
    assert.deepEqual(out.pricingAudit.missingBasisGroups, ['pc']);

    // PC publishes last time's rates unchanged - fulfilment still has a budget.
    assert.deepEqual(
        out.snapshot.rules.pc.tier_rates_halalah_per_million,
        memory.lastSuccessfulRates.pc,
    );

    // And it is unsellable, which is the only thing that makes that safe: a
    // price no supplier stands behind today must never reach a customer.
    assert.equal(out.snapshot.observations.availableCoins.pc, 0);

    // Console priced from its real market and stays on sale.
    assert.equal(out.snapshot.observations.availableCoins.console_fast, 156_464);
    assert.ok(
        out.snapshot.rules.console_fast.tier_rates_halalah_per_million[0] >
            memory.lastSuccessfulRates.console_fast[0],
        'console repriced against a market 300x last season',
    );
});

test('a run with nothing to carry forward still stops rather than guessing', async () => {
    const config = await runConfig();
    const caps = config.settings.tierCapsK;
    const utt = {
        source: 'utt', ratioEuroUsd: 1.15958,
        ps: { tiers: tiers(caps, 0.04), highestAvailable: null },
        pc: { tiers: tiers(caps, null, false), highestAvailable: null },
    };
    const fft = {
        source: 'fft', failed: false,
        cycle: {
            ps: { usdPerM: 220, amountCoins: 45_000, poolCoins: 312_929, coversTarget: false },
            pc: { usdPerM: null, amountCoins: 0, poolCoins: 0, coversTarget: false },
        },
        targeted: {
            ps: { tiers: tiers(caps, null, false), highestAvailable: null },
            pc: { tiers: tiers(caps, null, false), highestAvailable: null },
        },
    };

    await assert.rejects(
        () => runPrepare(config, fft, utt, {}),
        /no supplier cost basis for pc/,
    );
});

test('the ceiling follows the pool, not the buy that priced it', async () => {
    // 2026-09-17: one buy filled 45,000 and an hour later 18,000, while the
    // pool behind it was still hundreds of thousands deep. Selling against the
    // jumpier number would move the storefront's ceiling every hour.
    const config = await runConfig();
    const caps = config.settings.tierCapsK;
    const utt = {
        source: 'utt', ratioEuroUsd: 1.15958,
        ps: { tiers: tiers(caps, 0.04), highestAvailable: null },
        pc: { tiers: tiers(caps, 0.26), highestAvailable: null },
    };
    const run = (amountCoins, poolCoins) => runPrepare(config, {
        source: 'fft', failed: false,
        cycle: {
            ps: { usdPerM: 220, amountCoins, poolCoins, coversTarget: false },
            pc: { usdPerM: 240, amountCoins, poolCoins, coversTarget: false },
        },
        targeted: {
            ps: { tiers: tiers(caps, null, false), highestAvailable: null },
            pc: { tiers: tiers(caps, null, false), highestAvailable: null },
        },
    }, utt, {});

    const busy = await run(45_000, 312_929);
    const quiet = await run(18_000, 312_929);

    assert.equal(
        busy.snapshot.observations.availableCoins.console_fast,
        quiet.snapshot.observations.availableCoins.console_fast,
        'a jumpy fill size must not move the storefront ceiling',
    );
    assert.equal(
        busy.snapshot.observations.availableCoins.console_fast,
        156_464,
    );
});

test('the alert quotes what an order costs, not the rate per million', async () => {
    // "Fast 2M: 7.22 -> 850" is the number the formula works in, and nobody
    // buys it. The owner reads riyals per order, so that is what the snapshot
    // publishes for the alert to format.
    const config = await runConfig();
    const caps = config.settings.tierCapsK;
    const utt = {
        source: 'utt', ratioEuroUsd: 1.15958,
        ps: { tiers: tiers(caps, 0.04), highestAvailable: null },
        pc: { tiers: tiers(caps, 0.26), highestAvailable: null },
    };
    const fft = {
        source: 'fft', failed: false,
        cycle: {
            // Deep enough that a million coins is on sale: this case is about
            // the unit the alert quotes, not about what the market can fill.
            ps: { usdPerM: 220, amountCoins: 45_000, poolCoins: 20_000_000, coversTarget: false },
            pc: { usdPerM: 240, amountCoins: 45_000, poolCoins: 20_000_000, coversTarget: false },
        },
        targeted: {
            ps: { tiers: tiers(caps, null, false), highestAvailable: null },
            pc: { tiers: tiers(caps, null, false), highestAvailable: null },
        },
    };
    const memory = {
        lastSuccessfulRates: {
            console_normal: 722,
            console_fast: [722, 722, 722, 914, 1000, 1100],
            pc: [1777, 1836, 1836, 2132, 3968, 3968],
        },
    };

    const out = await runPrepare(config, fft, utt, memory);
    const points = out.pricingAudit.pricePoints;

    // Real order sizes, each with what it cost before and what it costs now.
    assert.ok(points.console_fast.length > 0);

    for (const point of points.console_fast) {
        assert.ok(Number.isInteger(point.quantity) && point.quantity > 0);
        assert.ok(Number.isInteger(point.currentHalalah) && point.currentHalalah > 0);
        assert.ok(Number.isInteger(point.previousHalalah) && point.previousHalalah > 0);
    }

    // A million coins costs about a thousand times the rate per million, which
    // is the whole reason the rate on its own tells the owner nothing.
    const million = points.console_fast.find((p) => p.quantity === 1_000_000);
    assert.ok(million, 'the one-million point must be quoted');
    assert.ok(
        million.currentHalalah > million.previousHalalah * 50,
        'an FC27 market against FC26 rates must show as an enormous move',
    );

    // Slow console stops at two million, so it must not be asked for twenty.
    assert.ok(
        points.console_normal.every((p) => p.quantity <= 2_000_000),
        'a group must only be quoted sizes it actually sells',
    );
});

test('with no baseline the alert has nothing to compare and says so rather than inventing it', async () => {
    const config = await runConfig();
    const caps = config.settings.tierCapsK;
    const utt = {
        source: 'utt', ratioEuroUsd: 1.15958,
        ps: { tiers: tiers(caps, 0.04), highestAvailable: null },
        pc: { tiers: tiers(caps, 0.26), highestAvailable: null },
    };
    const fft = {
        source: 'fft', failed: false,
        cycle: {
            // Deep enough that a million coins is on sale: this case is about
            // the unit the alert quotes, not about what the market can fill.
            ps: { usdPerM: 220, amountCoins: 45_000, poolCoins: 20_000_000, coversTarget: false },
            pc: { usdPerM: 240, amountCoins: 45_000, poolCoins: 20_000_000, coversTarget: false },
        },
        targeted: {
            ps: { tiers: tiers(caps, null, false), highestAvailable: null },
            pc: { tiers: tiers(caps, null, false), highestAvailable: null },
        },
    };

    const out = await runPrepare(config, fft, utt, {});

    for (const point of out.pricingAudit.pricePoints.console_fast) {
        assert.equal(point.previousHalalah, null);
        assert.ok(point.currentHalalah > 0);
    }
});

/** Runs the Assess Price Move node over what Validate Snapshot would hand it. */
async function runAssess(prepared, memory = {}) {
    const staticData = { global: { coinsPricingV2: memory } };
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
    const out = await fn({ first: () => ({ json: item }) }, (scope) => staticData[scope]);

    return out[0].json;
}

const DEEP_MARKET = {
    source: 'fft', failed: false,
    cycle: {
        ps: { usdPerM: 150.1, amountCoins: 4_635_000, poolCoins: 55_421_381, coversTarget: true },
        pc: { usdPerM: null, amountCoins: 0, poolCoins: 0, coversTarget: false },
    },
    targeted: {
        ps: { tiers: [], highestAvailable: null },
        pc: { tiers: [], highestAvailable: null },
    },
};

function deepUtt(caps) {
    return {
        source: 'utt', ratioEuroUsd: 1.15958,
        ps: { tiers: tiers(caps, 0.04), highestAvailable: null },
        pc: { tiers: tiers(caps, 0.26), highestAvailable: null },
    };
}

const STORE_BASELINE = {
    schemaVersion: 1,
    runId: '01M2QQ945KRHD19E2XQMNEB3FS',
    pricingVersion: 7,
    rates: {
        console_normal: 722,
        console_fast: [722, 722, 722, 914, 1000, 1100],
        pc: [1777, 1836, 1836, 2132, 3968, 3968],
    },
    cyclePSUsdPerM: 0.2,
    cyclePCUsdPerM: 0.2,
};

test('a run with no memory of its own asks the store what was last published', async () => {
    // n8n hands a MANUAL execution an empty static data object, so every run
    // someone pressed Execute on reported a healthy workflow as having nothing
    // to carry forward. The store knows.
    const config = await runConfig();
    const out = await runPrepare(
        config,
        DEEP_MARKET,
        deepUtt(config.settings.tierCapsK),
        {},
        STORE_BASELINE,
    );

    assert.equal(out.valid, true);
    assert.equal(out.pricingAudit.baselineSource, 'store');
    assert.deepEqual(out.pricingAudit.baselineRates, STORE_BASELINE.rates);

    // Signed with the pricing secret over timestamp, method and path - the
    // Code node cannot reach the HTTP credential the publish node uses.
    const [call] = out.__baselineCalls;
    assert.equal(call.method, 'GET');
    assert.equal(call.url, config.settings.baselineEndpoint);
    const expected = createHmac('sha256', 'test-secret')
        .update(`${call.headers['X-ArabUT-Timestamp']}\nGET\n/api/automation/v1/pricing/coins/baseline\n`)
        .digest('hex');
    assert.equal(call.headers['X-ArabUT-Signature'], expected);
});

test('the store wins over a memory that disagrees with it', async () => {
    // n8n does not persist static data written during a MANUAL execution, so a
    // manual run publishes a price the workflow then forgets. The next
    // scheduled run compared against the last SCHEDULED publish and reported
    // "سريع 1M: 1681.01 ← 725.73 (-56.8%)" against a store already selling at
    // 725.73. What the store charges is what a move is measured against.
    const config = await runConfig();
    const stale = {
        console_normal: 168_101,
        console_fast: [168_101, 168_101, 168_101, 168_101, 168_101, 168_101],
        pc: [1777, 1836, 1836, 2132, 3968, 3968],
    };
    const out = await runPrepare(
        config,
        DEEP_MARKET,
        deepUtt(config.settings.tierCapsK),
        { lastSuccessfulRates: stale },
        STORE_BASELINE,
    );

    assert.equal(out.pricingAudit.baselineSource, 'store');
    assert.deepEqual(out.pricingAudit.baselineRates, STORE_BASELINE.rates);
    assert.equal(out.__baselineCalls.length, 1);
});

test('a memory is what is left when the store cannot answer', async () => {
    const config = await runConfig();
    const out = await runPrepare(
        config,
        DEEP_MARKET,
        deepUtt(config.settings.tierCapsK),
        { lastSuccessfulRates: STORE_BASELINE.rates },
        null,
    );

    assert.equal(out.pricingAudit.baselineSource, 'memory');
    assert.deepEqual(out.pricingAudit.baselineRates, STORE_BASELINE.rates);
});

test('a store that cannot answer leaves the run exactly as it was', async () => {
    const config = await runConfig();
    const out = await runPrepare(config, DEEP_MARKET, deepUtt(config.settings.tierCapsK), {}, null);

    assert.equal(out.valid, true);
    assert.equal(out.pricingAudit.baselineSource, null);
    assert.equal(out.pricingAudit.baselineRates, null);
});

test('the approval gate reads the baseline the run resolved, not its own memory', async () => {
    // Otherwise a manual run - which has no memory at all - finds no baseline,
    // no large move, and publishes a season-turn price to the storefront
    // without anyone being asked.
    const config = await runConfig();
    const prepared = await runPrepare(
        config,
        DEEP_MARKET,
        deepUtt(config.settings.tierCapsK),
        {},
        STORE_BASELINE,
    );
    const assessed = await runAssess(prepared, {});

    assert.equal(assessed.baselineFound, true);
    assert.equal(assessed.approvalRequired, true);
    assert.ok(assessed.priceMove.largeCount > 0);
    assert.match(assessed.telegramMessage, /محتاجة اعتماد/);
});
