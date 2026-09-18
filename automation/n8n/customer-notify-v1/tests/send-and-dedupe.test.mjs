import assert from 'node:assert/strict';
import { test } from 'node:test';

import { considered, customerNotify, delivered } from './helpers.mjs';

const ACCEPTED = { statusCode: 201, body: { sent: true, message: { id: 'wamid.fixture' } } };

test('an accepted send is acknowledged and remembered', async () => {
    const flow = await considered();

    assert.equal(flow.json('Already Sent?').alreadySent, false);

    const [{ json }] = await delivered(flow, ACCEPTED);

    assert.equal(json.acknowledged, true);
    assert.equal(json.reason, null);
    assert.equal(json.order_number, 'AUT-7K2MQ4');
    assert.deepEqual(flow.memory.sentKeys, ['customer-notify:item:881:credentials:12043']);
});

test('the store retrying a message Whapi already took does not send it twice', async () => {
    const memory = {};

    const first = await considered(customerNotify(), { staticData: memory });
    await delivered(first, ACCEPTED);

    // The store never heard the acknowledgement - a timeout on the way back -
    // so it retries the same transition, which carries the same key.
    const retry = await considered(customerNotify(), { staticData: memory });

    assert.equal(retry.json('Already Sent?').alreadySent, true);
    assert.equal(memory.sentKeys.length, 1);
});

test('a genuine second hold on the same item is a different key and does send', async () => {
    const memory = {};

    const first = await considered(customerNotify(), { staticData: memory });
    await delivered(first, ACCEPTED);

    // Recovered, ran, stopped again: a new status-history row, so a new key.
    const recurrence = await considered(
        customerNotify({ idempotency_key: 'customer-notify:item:881:credentials:12099' }),
        { staticData: memory },
    );

    assert.equal(recurrence.json('Already Sent?').alreadySent, false);

    await delivered(recurrence, ACCEPTED);

    assert.equal(memory.sentKeys.length, 2);
});

test('a number Whapi rejects is not acknowledged and is not remembered', async () => {
    const flow = await considered();

    const [{ json }] = await delivered(flow, {
        statusCode: 404,
        body: { error: { message: 'recipient not found' } },
    });

    assert.equal(json.acknowledged, false);
    assert.equal(json.reason, 'whapi_rejected_404');
    // Not remembered: were it, the store's retry would be answered
    // "acknowledged" and a customer who was never messaged would be recorded
    // as messaged.
    assert.deepEqual(flow.memory.sentKeys, []);
});

test('a Whapi outage and an unreadable answer are both deferrals', async () => {
    const outage = await considered();
    const [{ json: outageResult }] = await delivered(outage, { statusCode: 502, body: 'bad gateway' });

    assert.equal(outageResult.acknowledged, false);
    assert.equal(outageResult.reason, 'whapi_unavailable_502');

    const thrown = await considered();
    const [{ json: thrownResult }] = await delivered(thrown, {
        error: { message: 'ETIMEDOUT gate.whapi.cloud' },
    });

    assert.equal(thrownResult.acknowledged, false);
    assert.equal(thrownResult.reason, 'ETIMEDOUT gate.whapi.cloud');
    assert.deepEqual(thrown.memory.sentKeys, []);
});

test('what leaves the workflow never carries the number or the message', async () => {
    const flow = await considered();
    const [{ json }] = await delivered(flow, ACCEPTED);
    const printed = JSON.stringify(json);

    assert.ok(!printed.includes('966512345678'), 'the recipient reached the result');
    assert.ok(!printed.includes('حساب EA'), 'the message body reached the result');
    assert.match(printed, /AUT-7K2MQ4/);
});

test('the remembered keys are bounded, newest first', async () => {
    const memory = { sentKeys: Array.from({ length: 2000 }, (_, index) => `old-key-${index}`) };

    const flow = await considered(customerNotify(), { staticData: memory });
    await delivered(flow, ACCEPTED);

    assert.equal(memory.sentKeys.length, 2000);
    assert.equal(memory.sentKeys[0], 'customer-notify:item:881:credentials:12043');
    assert.equal(memory.sentKeys.at(-1), 'old-key-1998');
});
