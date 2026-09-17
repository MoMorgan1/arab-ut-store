// Runs the Config and Prepare Coins Snapshot nodes of workflow-v2.7.json the
// way n8n would - once with FFT answering, once with FFT down - and pins the
// v2.5 rule: FFT down carries last time's rates forward and still publishes a
// UTT cost table; with nothing to carry forward it stops as v2.4 did.
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';

const workflow = JSON.parse(readFileSync(new URL('../workflow-v2.7.json', import.meta.url), 'utf8'));
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

async function runPrepare(config, fft, utt, memory) {
    const staticData = { global: { coinsPricingV2: memory } };
    const lookup = (name) => ({ first: () => ({ json: name === 'Config' ? config : {} }) });
    const input = { all: () => [{ json: fft }, { json: utt }], first: () => ({ json: fft }) };
    const fn = new AsyncFunction('$', '$input', '$getWorkflowStaticData', prepareSrc);
    const out = await fn(lookup, input, (scope) => staticData[scope]);

    return out[0].json;
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
            ps: { usdPerM: 300, amountCoins: 45_000, coversTarget: false },
            pc: { usdPerM: null, amountCoins: 0, coversTarget: false },
        },
        targeted: {
            ps: { tiers: tiers(caps, null, false), highestAvailable: null },
            pc: { tiers: tiers(caps, null, false), highestAvailable: null },
        },
    };

    const out = await runPrepare(config, fft, utt, {});

    assert.equal(out.valid, true);
    assert.deepEqual(out.snapshot.observations.availableCoins, {
        console_normal: 45_000,
        console_fast: 45_000,
        // Zero is published as zero. A platform with no market has to be able
        // to say so, or the storefront keeps offering twenty million coins.
        pc: 0,
    });
    // legalRanges is checked for equality against the store's own settings and
    // must therefore stay exactly as configured, whatever the market says.
    assert.equal(out.snapshot.legalRanges.pc.maximum, 20_000_000);
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
            ps: { usdPerM: 12, amountCoins: 90_000_000, coversTarget: true },
            pc: { usdPerM: 20, amountCoins: 90_000_000, coversTarget: true },
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
            ps: { usdPerM: 220, amountCoins: 45_000, coversTarget: false },
            pc: { usdPerM: null, amountCoins: 0, coversTarget: false },
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
    assert.equal(out.snapshot.observations.availableCoins.console_fast, 45_000);
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
            ps: { usdPerM: 220, amountCoins: 45_000, coversTarget: false },
            pc: { usdPerM: null, amountCoins: 0, coversTarget: false },
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
