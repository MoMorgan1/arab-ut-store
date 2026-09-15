import assert from 'node:assert/strict';
import { createHmac } from 'node:crypto';
import { test } from 'node:test';

import { FULFILLMENT_SECRET, NOW_SECONDS, solveAccepted, validated } from './helpers.mjs';

async function submitted(answer) {
    const flow = await validated();
    flow.set('SBC: Submit Solve', answer);

    return flow;
}

test('an accepted solve is reported once, in the challenge phase, with every solve id and the first as the reference', async () => {
    const flow = await submitted(solveAccepted());
    const [report] = await flow.run('Extract Solve Ids', 'extract-solve-ids', flow.get('SBC: Submit Solve'));

    assert.deepEqual(report.json, {
        orderId: 'AUT-7K2MQ4',
        order_item_public_id: '01K52J0V3D5S9F2G6H8J1K4L7Z',
        supplier: 'fft',
        supplier_order_id: '7d0b7f2e-6a1c-4d3f-9b8e-1c2d3e4f5a61',
        delivery_phase: 'challenge',
        challenge_ids: ['7d0b7f2e-6a1c-4d3f-9b8e-1c2d3e4f5a61', '7d0b7f2e-6a1c-4d3f-9b8e-1c2d3e4f5a62'],
    });
});

test('the answer may arrive parsed, as one object, or with numeric ids', async () => {
    const parsed = await submitted({ data: [{ status: 'processed', sbcSolveID: 'a1' }] });
    const [one] = await parsed.run('Extract Solve Ids', 'extract-solve-ids', parsed.get('SBC: Submit Solve'));
    assert.deepEqual(one.json.challenge_ids, ['a1']);

    const single = await submitted({ status: 'processed', sbcSolveID: 8837410 });
    const [two] = await single.run('Extract Solve Ids', 'extract-solve-ids', single.get('SBC: Submit Solve'));
    assert.equal(two.json.supplier_order_id, '8837410');
});

test('a refused solve stops the run naming the order and the reason, as v14\'s alert did', async () => {
    const refused = await submitted({ data: JSON.stringify([{ status: 'error', error: 'no customer found' }]) });
    await assert.rejects(
        refused.run('Extract Solve Ids', 'extract-solve-ids', refused.get('SBC: Submit Solve')),
        /order AUT-7K2MQ4: FFT رفض تقديم التحدي "Team of the Week Upgrade": no customer found — غالباً ما فيه عميل بهذا الإيميل/,
    );

    const noId = await submitted({ data: JSON.stringify([{ status: 'processed' }]) });
    await assert.rejects(
        noId.run('Extract Solve Ids', 'extract-solve-ids', noId.get('SBC: Submit Solve')),
        /قبل التحدي بدون رقم حل/,
    );

    const garbage = await submitted({ data: '<html>Too Many Requests</html>' });
    await assert.rejects(
        garbage.run('Extract Solve Ids', 'extract-solve-ids', garbage.get('SBC: Submit Solve')),
        /رد غير مقروء: <html>Too Many Requests/,
    );
});

test('the report is signed the way the store verifies it, over the exact bytes sent, challenge ids included', async () => {
    const flow = await submitted(solveAccepted());
    await flow.run('Extract Solve Ids', 'extract-solve-ids', flow.get('SBC: Submit Solve'));
    const [signed] = await flow.run('Sign Placement Report', 'sign-report', flow.get('Extract Solve Ids'));

    const expected = createHmac('sha256', FULFILLMENT_SECRET)
        .update(`${signed.json.timestamp}\n${signed.json.event}\n${signed.json.rawBody}`)
        .digest('hex');

    assert.equal(signed.json.signature, expected);
    assert.equal(signed.json.timestamp, String(NOW_SECONDS));
    assert.match(signed.json.event, /^[0-9A-HJKMNP-TV-Z]{26}$/);
    assert.deepEqual(JSON.parse(signed.json.rawBody), {
        order_item_public_id: '01K52J0V3D5S9F2G6H8J1K4L7Z',
        supplier: 'fft',
        supplier_order_id: '7d0b7f2e-6a1c-4d3f-9b8e-1c2d3e4f5a61',
        delivery_phase: 'challenge',
        challenge_ids: ['7d0b7f2e-6a1c-4d3f-9b8e-1c2d3e4f5a61', '7d0b7f2e-6a1c-4d3f-9b8e-1c2d3e4f5a62'],
    });
});

function acknowledged() {
    return {
        statusCode: 200,
        body: { data: { acknowledged: true, order_item_public_id: '01K52J0V3D5S9F2G6H8J1K4L7Z', supplier: 'fft', supplier_order_id: '7d0b7f2e-6a1c-4d3f-9b8e-1c2d3e4f5a61', job_public_id: 'JOB-1' } },
    };
}

test('the report acknowledged: the store is answered acknowledged with what was placed', async () => {
    const flow = await validated();
    const [answer] = await flow.run('Confirm Report', 'confirm-report', [acknowledged()]);

    assert.equal(answer.json.acknowledged, true);
    assert.equal(answer.json.placed.job_public_id, 'JOB-1');

    const string = await validated();
    const [fromString] = await string.run('Confirm Report', 'confirm-report', [{ statusCode: 200, body: JSON.stringify(acknowledged().body) }]);
    assert.equal(fromString.json.acknowledged, true);
});

test('a report the store refuses stops the run naming the code', async () => {
    const refused = await validated();
    await assert.rejects(
        refused.run('Confirm Report', 'confirm-report', [
            { statusCode: 422, body: { error: { code: 'invalid_challenge_ids', message: 'One or more challenge ids are not in the expected shape.' } } },
        ]),
        /order AUT-7K2MQ4: the store refused the placement report \(HTTP 422 invalid_challenge_ids/,
    );

    const none = await validated();
    await assert.rejects(none.run('Confirm Report', 'confirm-report', []), /0 report\(s\) answered for one challenge/);
});
