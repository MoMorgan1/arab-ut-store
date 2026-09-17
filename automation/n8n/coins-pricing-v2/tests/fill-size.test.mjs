// Runs the Probe FFT node of workflow-v3.0.json against a fake FFT, and pins
// the v2.6 rule: the clearing price comes from how many coins a price would
// actually fill, not from whether the provider says the word "enough".
//
// The market these cases describe is the real one, measured on 2026-09-17:
// the whole PlayStation cycle pool held 858,328 coins across fourteen
// accounts, a single buy topped out at 45,000 whatever was offered, and PC
// held nothing at all.
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';

const workflow = JSON.parse(
    readFileSync(new URL('../workflow-v3.0.json', import.meta.url), 'utf8'),
);
const source = (name) =>
    workflow.nodes.find((node) => node.name === name).parameters.jsCode;
const AsyncFunction = Object.getPrototypeOf(async function () {}).constructor;

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

/**
 * A market that fills `fill(pricePer100K)` coins at a given offer.
 *
 * `calls` records every request so a test can assert the probe did not walk
 * the market forever looking for a word it will never hear.
 */
function market(fill, { sellerHint = 22 } = {}) {
    const calls = [];

    return {
        calls,
        async httpRequest({ body }) {
            const request = JSON.parse(body);
            calls.push(request);

            if (request.role === 'seller') {
                return { avgFillPrice: sellerHint };
            }

            const coins = fill(Number(request.price));

            return {
                hasStock: coins > 0,
                coverage: coins > 0 ? 'limited' : 'none',
                estimatedCoinsAtPrice: coins,
            };
        },
    };
}

async function probe(psMarket, pcMarket = psMarket) {
    const config = await runConfig();
    const helpers = {
        async httpRequest(options) {
            const platform = JSON.parse(options.body).platform;

            return (platform === 'PC' ? pcMarket : psMarket).httpRequest(
                options,
            );
        },
    };
    const fn = new AsyncFunction(
        '$',
        '$env',
        'helpers',
        `const this_ = { helpers }; return (async function () { ${source('Probe FFT').replace('const helpers = this.helpers;', '')} }).call(this_);`,
    );
    const out = await fn(
        () => ({ first: () => ({ json: config }) }),
        { FFT_API_USER: 'user', FFT_API_KEY: 'key' },
        helpers,
    );

    return Array.isArray(out) ? out[0].json : out.json;
}

test('a market that can never fill the target still yields a price and a ceiling', async () => {
    // FC27 on 2026-09-17: nothing above 45,000 coins at any offer, so the old
    // ladder - which needed coverage "enough" for two million - reported no
    // clearing price at all, every hour for twelve days.
    const ps = market((price) =>
        price < 22 ? 0 : price < 26 ? 18_000 : 45_000,
    );
    const result = await probe(ps);

    assert.equal(result.failed, false);
    assert.ok(result.cycle.ps.usdPerM > 0, 'a price was found');
    assert.equal(result.cycle.ps.amountCoins, 45_000);
    assert.equal(result.cycle.ps.coversTarget, false);
    assert.equal(result.cycle.ps.failureReason, undefined);
});

test('the price is where the fill size stops growing, not the highest offer tried', async () => {
    const ps = market((price) => (price < 30 ? 0 : 45_000));
    const result = await probe(ps);

    // Everything at or above thirty fills the same 45,000 coins. Paying more
    // buys nothing, so the customer is priced at where the plateau begins.
    assert.ok(
        result.cycle.ps.clearingUsdPer100K >= 30 &&
            result.cycle.ps.clearingUsdPer100K < 34,
        `clearing price was ${result.cycle.ps.clearingUsdPer100K}`,
    );
});

test('a deep market still stops at the cheapest price that fills the order', async () => {
    // The old behaviour, unchanged: when the target is reachable the search
    // ends there and never explores the expensive end of the book.
    const ps = market((price) => (price < 5 ? 0 : 5_000_000));
    const result = await probe(ps);

    assert.equal(result.cycle.ps.coversTarget, true);
    assert.ok(result.cycle.ps.amountCoins >= result.cycle.ps.targetCoins);
    // Priced at the edge of the book, not at the provider's hint: the search
    // narrows back down once it knows the target is reachable.
    assert.ok(
        result.cycle.ps.clearingUsdPer100K >= 5 &&
            result.cycle.ps.clearingUsdPer100K < 6,
        `clearing price was ${result.cycle.ps.clearingUsdPer100K}`,
    );
});

test('a platform with no coins at any price says so instead of inventing one', async () => {
    const empty = market(() => 0, { sellerHint: null });
    const result = await probe(empty);

    assert.equal(result.cycle.ps.clearingUsdPer100K, null);
    assert.equal(result.cycle.ps.amountCoins, 0);
    assert.match(result.cycle.ps.failureReason, /no coins to sell/);
});

test('an unreadable fill size is refused rather than read as "offer more"', async () => {
    // v2.4 collapsed an unreadable coverage word to "none", which the ladder
    // read as "go higher" - that is how a clearing price hundreds of times
    // above the market once got recorded as real.
    const broken = {
        calls: [],
        async httpRequest({ body }) {
            const request = JSON.parse(body);

            return request.role === 'seller'
                ? { avgFillPrice: 22 }
                : { coverage: 'limited' };
        },
    };
    const result = await probe(broken);

    assert.ok(
        !(result.cycle?.ps?.clearingUsdPer100K > 0),
        'an unreadable market must not produce a price',
    );
    assert.match(
        String(
            result.cycle?.ps?.failureReason ??
                result.failureReason ??
                JSON.stringify(result),
        ),
        /no readable fill size/i,
    );
});
