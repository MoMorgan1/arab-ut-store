import assert from 'node:assert/strict';
import { test } from 'node:test';

import { coinsItem, planned, uttStocks } from './helpers.mjs';

const UTT_PREDICT = 'https://utautotransfer.com/api/getMaxOrderPrediction';
const FFT_PREVIEW = 'https://futtransfer.top/maxOrderPreviewAPI';

/**
 * The engine asks UTT how much a stock combination can move and FFT how much
 * it could move at a test price. `predict` answers UTT per call index, `fft`
 * answers FFT.
 */
async function decide({ items = [coinsItem()], stocks, predict, fft }) {
    const flow = await planned(items, {
        httpRequest: async (options) => {
            if (options.url === UTT_PREDICT) {
                const section = decodeURIComponent(options.body.match(/stockSection=([^&]*)/)[1]);

                return predict(section.split(',').length / 2);
            }

            if (options.url === FFT_PREVIEW) {
                return fft(JSON.parse(options.body));
            }

            throw new Error(`unexpected call to ${options.url}`);
        },
    });

    await flow.run('Shipment', 'shipment', flow.get('Plan Shipment'));
    flow.set('UTT: Get Stocks', uttStocks(stocks));
    await flow.run('Supplier Decision Engine', 'supplier-decision', flow.get('UTT: Get Stocks'));

    return flow;
}

test('full UTT coverage and an FFT preview with ten percent headroom: FFT wins', async () => {
    const flow = await decide({
        stocks: [[285, 0.9, 400000], [359, 0.95, 400000]],
        predict: (count) => ({ maxTransferable: count * 800000, orderCardsRemaining: 8 }),
        fft: (body) => {
            assert.equal(body.apiUser, 'fixture@example.com');
            assert.equal(body.apiKey, 'fixture-key');
            assert.equal(body.platform, 'PS');
            assert.equal(body.riskLevel, 10);
            // 1,250K sits in the 300K+ ladder rung: max(350000, 300000).
            assert.equal(body.customerBalance, 350000);

            return { success: true, maxPossibleK: 1400 };
        },
    });
    const decision = flow.json('Supplier Decision Engine');

    assert.equal(decision.supplier, 'FFT');
    assert.equal(decision.forcedUTT, false);
    assert.equal(decision.uttMaxK, 1600);
    // 800K at 0.9 and the remaining 450K at 0.95 blend to 0.918; minus the
    // 0.09 undercut, rounded to cents.
    assert.equal(decision.fftTestPrice, 0.83);
    assert.equal(decision.calculatedMaxPrice, 1);
});

test('full UTT coverage and an FFT preview that falls short: UTT, with the failover pads', async () => {
    const flow = await decide({
        stocks: [[285, 0.9, 400000], [359, 0.95, 400000], [400, 0.99, 400000]],
        predict: (count) => ({ maxTransferable: count * 800000, orderCardsRemaining: 8 }),
        fft: () => ({ success: true, maxPossibleK: 1300 }),
    });
    const decision = flow.json('Supplier Decision Engine');

    assert.equal(decision.supplier, 'UTT');
    assert.equal(decision.isPartialFulfillment, false);
    assert.equal(decision.uttPublicSaleString, '285,0.9,359,0.95,400,0.99');
});

test('fewer than three UTT cards left forces UTT whatever FFT would say', async () => {
    const flow = await decide({
        stocks: [[285, 0.9, 400000]],
        predict: () => ({ maxTransferable: 300000, orderCardsRemaining: 2 }),
        fft: () => {
            throw new Error('FFT must not be asked');
        },
    });
    const decision = flow.json('Supplier Decision Engine');

    assert.equal(decision.forcedUTT, true);
    assert.equal(decision.supplier, 'UTT');
    assert.equal(decision.isPartialFulfillment, true);
    assert.equal(decision.uttCardsRemaining, 2);
});

test('neither side covers the order: whoever moves more right now, and FFT keeps the tie', async () => {
    const uttAhead = await decide({
        stocks: [[285, 0.9, 400000]],
        predict: () => ({ maxTransferable: 900000, orderCardsRemaining: 8 }),
        fft: (body) => {
            // Partial coverage asks FFT at the full budget, not an undercut.
            assert.equal(body.maxPrice, 1);

            return { success: true, maxPossibleK: 600 };
        },
    });
    assert.equal(uttAhead.json('Supplier Decision Engine').supplier, 'UTT');
    assert.equal(uttAhead.json('Supplier Decision Engine').isPartialFulfillment, true);

    const tie = await decide({
        stocks: [[285, 0.9, 400000]],
        predict: () => ({ maxTransferable: 900000, orderCardsRemaining: 8 }),
        fft: () => ({ success: true, maxPossibleK: 800 }),
    });
    assert.equal(tie.json('Supplier Decision Engine').supplier, 'FFT');

    const fftCovers = await decide({
        stocks: [[285, 0.9, 400000]],
        predict: () => ({ maxTransferable: 900000, orderCardsRemaining: 8 }),
        fft: () => ({ success: true, maxPossibleK: 1250 }),
    });
    assert.equal(fftCovers.json('Supplier Decision Engine').supplier, 'FFT');
});

test('stocks over the budget or too small to matter are never tried', async () => {
    const flow = await decide({
        stocks: [[1, 1.2, 400000], [2, 0.9, 10000]],
        predict: () => {
            throw new Error('UTT must not be asked about an unusable stock');
        },
        fft: () => ({ success: true, maxPossibleK: 100 }),
    });
    const decision = flow.json('Supplier Decision Engine');

    assert.equal(decision.stocksTested, 0);
    assert.equal(decision.supplier, 'FFT');
    assert.equal(decision.uttPublicSaleString, null);
});

test('a supplier that cannot be reached leaves the decision to the other side', async () => {
    const flow = await decide({
        stocks: [[285, 0.9, 400000], [359, 0.95, 400000]],
        predict: () => {
            throw new Error('UTT timed out');
        },
        fft: () => ({ success: true, maxPossibleK: 1250 }),
    });

    assert.equal(flow.json('Supplier Decision Engine').supplier, 'FFT');
    assert.equal(flow.json('Supplier Decision Engine').stocksTested, 2);
});
