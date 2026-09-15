// Runs the Config and Prepare Coins Snapshot nodes of workflow-v2.5.json the
// way n8n would - once with FFT answering, once with FFT down - and pins the
// v2.5 rule: FFT down carries last time's rates forward and still publishes a
// UTT cost table; with nothing to carry forward it stops as v2.4 did.
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';

const workflow = JSON.parse(readFileSync(new URL('../workflow-v2.5.json', import.meta.url), 'utf8'));
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
