import assert from 'node:assert/strict';
import { createHmac } from 'node:crypto';
import { test } from 'node:test';

import { coinsItem, FULFILLMENT_SECRET, NOW_SECONDS, planned, sbcItem } from './helpers.mjs';

async function shipped(items, { utt, fft } = {}) {
    const flow = await planned(items);
    await flow.run('Shipment', 'shipment', flow.get('Plan Shipment'));

    if (utt !== undefined) {
        flow.set('UTT: Add Order Public', utt);
    }

    if (fft !== undefined) {
        flow.set('External: Buy Coins', fft);
    }

    return flow;
}

test('a UTT placement is reported once per funded item under the same reference', async () => {
    const flow = await shipped([coinsItem(), sbcItem()], { utt: { order: { idOrder: 48812, statusOrder: 'PENDING' } } });
    const reports = await flow.run('Extract Reference', 'extract-reference', flow.get('UTT: Add Order Public'));

    assert.deepEqual(
        reports.map(({ json }) => json),
        [
            { orderId: 'AUT-7K2MQ4', order_item_public_id: '01K52J0V3C1A8W4E7R2T9Y6U3I', supplier: 'utt', supplier_order_id: '48812', delivery_phase: 'coins' },
            { orderId: 'AUT-7K2MQ4', order_item_public_id: '01K52J0V3D5S9F2G6H8J1K4L7Z', supplier: 'utt', supplier_order_id: '48812', delivery_phase: 'coins' },
        ],
    );
});

test('an FFT placement answers its order id inside a JSON string, and that is the reference', async () => {
    const flow = await shipped([coinsItem()], { fft: { data: JSON.stringify({ success: true, orderID: 'FFT-9917' }) } });
    const [report] = await flow.run('Extract Reference', 'extract-reference', flow.get('External: Buy Coins'));

    assert.equal(report.json.supplier, 'fft');
    assert.equal(report.json.supplier_order_id, 'FFT-9917');
});

test('no reference from either supplier is an in-band rejection and stops the run with the reason', async () => {
    const rejected = await shipped([coinsItem()], { fft: { data: JSON.stringify({ success: false, error: 'Too Many Requests' }) } });
    await assert.rejects(
        rejected.run('Extract Reference', 'extract-reference', rejected.get('External: Buy Coins')),
        /order AUT-7K2MQ4: المورد رفض طلب الشحن: Too Many Requests — غالباً ضغط طلبات/,
    );

    const silent = await shipped([coinsItem()], { utt: { message: 'Invalid api key' } });
    await assert.rejects(
        silent.run('Extract Reference', 'extract-reference', silent.get('UTT: Add Order Public')),
        /المورد رفض طلب الشحن: Invalid api key/,
    );
});

test('each report is signed the way the store verifies it, over the exact bytes sent', async () => {
    const flow = await shipped([coinsItem(), sbcItem()], { utt: { order: { idOrder: 48812 } } });
    await flow.run('Extract Reference', 'extract-reference', flow.get('UTT: Add Order Public'));
    const signed = await flow.run('Sign Placement Reports', 'sign-reports', flow.get('Extract Reference'));

    assert.equal(signed.length, 2);

    for (const { json } of signed) {
        // What VerifyN8nFulfillmentSignature computes on the store.
        const expected = createHmac('sha256', FULFILLMENT_SECRET)
            .update(`${json.timestamp}\n${json.event}\n${json.rawBody}`)
            .digest('hex');

        assert.equal(json.signature, expected);
        assert.equal(json.timestamp, String(NOW_SECONDS));
        assert.match(json.event, /^[0-9A-HJKMNP-TV-Z]{26}$/);
        assert.deepEqual(JSON.parse(json.rawBody), {
            order_item_public_id: json.order_item_public_id,
            supplier: 'utt',
            supplier_order_id: '48812',
            delivery_phase: 'coins',
        });
    }
});

function acknowledged(id) {
    return {
        statusCode: 200,
        body: { data: { acknowledged: true, order_item_public_id: id, supplier: 'utt', supplier_order_id: '48812', job_public_id: `JOB-${id}` } },
    };
}

test('every report acknowledged and nothing remaining: the store is answered acknowledged', async () => {
    const flow = await shipped([coinsItem(), sbcItem()]);
    const [answer] = await flow.run('Confirm Reports', 'confirm-reports', [
        acknowledged('01K52J0V3C1A8W4E7R2T9Y6U3I'),
        acknowledged('01K52J0V3D5S9F2G6H8J1K4L7Z'),
    ]);

    assert.equal(answer.json.acknowledged, true);
    assert.equal(answer.json.remaining, 0);
    assert.deepEqual(answer.json.placed.map((entry) => entry.job_public_id), ['JOB-01K52J0V3C1A8W4E7R2T9Y6U3I', 'JOB-01K52J0V3D5S9F2G6H8J1K4L7Z']);
});

test('a shipment placed while another account waits answers not acknowledged, so the store sends the rest', async () => {
    const flow = await planned([
        coinsItem(),
        coinsItem({ order_item_public_id: '01K52J0V3E9Q1W3E5R7T9Y2U4I', account: { ...coinsItem().account, ea_email: 'other@example.test' } }),
    ]);
    await flow.run('Shipment', 'shipment', flow.get('Plan Shipment'));
    const [answer] = await flow.run('Confirm Reports', 'confirm-reports', [acknowledged('01K52J0V3C1A8W4E7R2T9Y6U3I')]);

    assert.equal(answer.json.acknowledged, false);
    assert.equal(answer.json.remaining, 1);
});

test('a report the store refuses stops the run naming the code, and a missing answer stops it too', async () => {
    const refused = await shipped([coinsItem()]);
    await assert.rejects(
        refused.run('Confirm Reports', 'confirm-reports', [
            { statusCode: 409, body: { error: { code: 'supplier_reference_conflict', message: 'This supplier order reference is already recorded on an item of another order.' } } },
        ]),
        /order AUT-7K2MQ4: the store refused the placement report \(HTTP 409 supplier_reference_conflict/,
    );

    const short = await shipped([coinsItem(), sbcItem()]);
    await assert.rejects(
        short.run('Confirm Reports', 'confirm-reports', [acknowledged('01K52J0V3C1A8W4E7R2T9Y6U3I')]),
        /1 report\(s\) answered for 2 item\(s\)/,
    );

    const string = await shipped([coinsItem()]);
    const [answer] = await string.run('Confirm Reports', 'confirm-reports', [
        { statusCode: 200, body: JSON.stringify(acknowledged('01K52J0V3C1A8W4E7R2T9Y6U3I').body) },
    ]);
    assert.equal(answer.json.acknowledged, true);
});
