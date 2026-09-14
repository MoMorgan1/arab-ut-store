import assert from 'node:assert/strict';
import { test } from 'node:test';

import { coinsItem, fftSbcs, planned, sbcItem } from './helpers.mjs';

test('a priced challenge sets the shipment amount: FFT price per solve, times the solves, plus the coins beside it', async () => {
    const flow = await planned([coinsItem(), sbcItem()]);

    // n8n hands the FFT list over as one item per challenge.
    await flow.run('SBC: Match & Validate', 'match-challenges', fftSbcs());
    await flow.run('Shipment', 'shipment', flow.get('SBC: Match & Validate'));

    const shipment = flow.json('Shipment');

    // ceil(145000 / 1000) = 145 per solve, two solves, plus 1250 extra.
    assert.equal(shipment.amountK, 145 * 2 + 1250);
    assert.equal(shipment.sbcName, 'Team of the Week Upgrade');
    assert.equal(shipment.challengeAmount, 4);
    assert.deepEqual(shipment.sbcSetIDs.map(({ setID, coinsPerSolveK, timesToSolve, orderItemPublicId }) => [setID, coinsPerSolveK, timesToSolve, orderItemPublicId]), [
        [412, 145, 2, '01K52J0V3D5S9F2G6H8J1K4L7Z'],
    ]);
    // Everything the HTTP nodes read survived the merge.
    assert.equal(shipment.eaEmail, 'fahad@example.test');
    assert.equal(shipment.platform, 'PS');
    assert.deepEqual(shipment.itemIds, ['01K52J0V3C1A8W4E7R2T9Y6U3I', '01K52J0V3D5S9F2G6H8J1K4L7Z']);
});

test('the list may also arrive as one item wrapping the array', async () => {
    const flow = await planned([sbcItem()]);

    await flow.run('SBC: Match & Validate', 'match-challenges', [{ json: { data: fftSbcs() } }]);

    assert.equal(flow.json('SBC: Match & Validate').amountK, 290);
});

test('a challenge FFT does not price, or does not list, stops the run with the v14 message and the order number', async () => {
    const unpriced = await planned([sbcItem({ sbc: { set_id: 413, times_to_solve: 1 } })]);
    await assert.rejects(
        unpriced.run('SBC: Match & Validate', 'match-challenges', fftSbcs()),
        /order AUT-7K2MQ4: FFT ما مسعّر هذا التحدي حالياً .*"Unpriced" \[منصة PS\]/,
    );

    const unlisted = await planned([sbcItem({ sbc: { set_id: 999, times_to_solve: 1 } })]);
    await assert.rejects(
        unlisted.run('SBC: Match & Validate', 'match-challenges', fftSbcs()),
        /التحدي غير موجود: setID 999/,
    );

    const empty = await planned([sbcItem()]);
    await assert.rejects(empty.run('SBC: Match & Validate', 'match-challenges', [{ json: { data: [] } }]), /فشل قراءة قائمة التحديات/);
});

test('the Shipment node refuses a shipment with nothing to place', async () => {
    const flow = await planned([coinsItem()]);

    await assert.rejects(
        flow.run('Shipment', 'shipment', [{ json: { ...flow.json('Plan Shipment'), amountK: 0 } }]),
        /order AUT-7K2MQ4: the shipment carries no coins/,
    );
});
